"""The saved script controls the spoken order of a Vobiz call."""

import asyncio
import json
import os
import unittest
from datetime import datetime, timedelta, timezone
from unittest.mock import AsyncMock, patch

import httpx

from app import scripted_voice_bridge


class FakeUpstream:
    def __init__(self) -> None:
        self.events = asyncio.Queue()

    async def __aenter__(self):
        return self

    async def __aexit__(self, *_):
        return None

    async def send(self, _):
        pass

    def __aiter__(self):
        return self

    async def __anext__(self):
        return await self.events.get()


class FakeWebSocket:
    query_params = {"attempt_id": "1", "expires": "0", "signature": "test"}

    def __init__(self, upstream: FakeUpstream, answers: list[str | list[str] | None]) -> None:
        self.upstream = upstream
        self.answers = iter(answers)
        self.events = asyncio.Queue()
        self.closed = False

    async def accept(self):
        pass

    async def close(self, **_):
        self.closed = True

    async def send_json(self, event):
        if event["event"] == "checkpoint":
            await self.events.put({"event": "playedStream", "name": event["name"]})
            answer = next(self.answers)
            if answer is not None:
                async def reply():
                    await asyncio.sleep(0.01)
                    for text in answer if isinstance(answer, list) else [answer]:
                        await self.upstream.events.put(json.dumps({
                            "type": "conversation.item.input_audio_transcription.completed",
                            "transcript": text,
                            "usage": {"input_tokens": 10, "output_tokens": 2,
                                      "input_token_details": {"audio_tokens": 8}},
                        }))
                asyncio.create_task(reply())

    async def iter_text(self):
        yield json.dumps({"event": "start", "start": {"streamId": "stream-1", "mediaFormat": {
            "encoding": "audio/x-l16", "sampleRate": 16000,
        }}})
        while not self.closed:
            yield json.dumps(await self.events.get())


class FakeClient:
    def __init__(self, context=None) -> None:
        self.context = context
        self.messages = []
        self.outcomes = []
        self.hangups = 0
        self.callbacks = []
        self.usages = []

    async def __aenter__(self):
        return self

    async def __aexit__(self, *_):
        return None

    async def get(self, url, **_):
        return httpx.Response(200, json=self.context, request=httpx.Request("GET", "http://backend" + url))

    async def post(self, url, **kwargs):
        if url.endswith("/chat/completions"):
            when = datetime.now(timezone(timedelta(hours=5, minutes=30))) + timedelta(days=1)
            return httpx.Response(200, json={"choices": [{"message": {"content": json.dumps({
                "requested_at": when.isoformat(timespec="seconds"),
            })}}]}, request=httpx.Request("POST", url))
        if url.endswith("/messages"):
            self.messages.append(kwargs["json"])
        elif url.endswith("/outcome"):
            self.outcomes.append(kwargs["json"]["reason"])
        elif url.endswith("/callback"):
            self.callbacks.append(kwargs["json"]["requested_at"])
        elif url.endswith("/usage"):
            self.usages.append(kwargs["json"])
        return httpx.Response(200, request=httpx.Request("POST", url))

    async def delete(self, url, **_):
        self.hangups += 1
        return httpx.Response(200, request=httpx.Request("DELETE", url))


class ScriptedFlowTests(unittest.IsolatedAsyncioTestCase):
    async def run_call(self, answers: list[str | None]):
        context = {
            "introduction": "Am I speaking with Asha?",
            "availability_question": "Is this a convenient time?",
            "introduction_statement": "I am calling about your application.",
            "questions": [
                {"id": "q1", "text": "Are you working?"},
                {"id": "q2", "text": "Current CTC?", "ask_if": "q1_yes"},
                {"id": "q3", "text": "Notice period?", "ask_if": "q1_yes"},
                {"id": "q4", "text": "Expected CTC?"},
                {"id": "q5", "text": "Years of experience?"},
            ],
            "unavailable": "When may I call back?",
            "callback_confirmation": "Call on {callback_date} at {callback_time}, India time?",
            "callback_scheduled": "Your callback is scheduled. Bye.",
            "callback_declined": "Thank you for your time. Bye.",
            "conclusion": "Thank you. Bye.",
            "provider_call_id": "call-1",
        }
        upstream = FakeUpstream()
        websocket = FakeWebSocket(upstream, answers)
        backend = FakeClient(context)
        provider = FakeClient()
        with patch.dict(os.environ, {"OPENAI_API_KEY": "test", "VOBIZ_BRIDGE_TOKEN": "test",
                                  "VOBIZ_AUTH_ID": "test", "VOBIZ_AUTH_TOKEN": "test"}), \
             patch.object(scripted_voice_bridge, "authorized_stream", return_value=True), \
             patch.object(scripted_voice_bridge, "connect", return_value=upstream), \
             patch.object(scripted_voice_bridge.httpx, "AsyncClient", side_effect=[backend, provider]), \
             patch.object(scripted_voice_bridge, "synthesize_speech", new_callable=AsyncMock, return_value=b"\0\0"):
            await asyncio.wait_for(scripted_voice_bridge.bridge(websocket), 3)
        return backend, provider

    async def test_no_current_job_skips_ctc_and_notice_after_two_intro_questions(self):
        backend, provider = await self.run_call(["Yes", "Yes", None, "No", "40 thousand", "Five years", None])
        self.assertEqual([m["message"] for m in backend.messages if m["speaker"] == "ai"], [
            "Am I speaking with Asha?", "Is this a convenient time?",
            "I am calling about your application.", "Are you working?",
            "Expected CTC?", "Years of experience?", "Thank you. Bye.",
        ])
        self.assertEqual(backend.outcomes, ["completed_questions"])
        self.assertEqual(provider.hangups, 1)

    async def test_wrong_person_ends_before_screening(self):
        backend, provider = await self.run_call(["No", None])
        self.assertEqual(backend.outcomes, ["wrong_person"])
        self.assertEqual(provider.hangups, 1)
        self.assertFalse(any(m["message"] == "Are you working?" for m in backend.messages))

    async def test_current_job_asks_all_five_questions(self):
        backend, _ = await self.run_call(["Yes", "Yes", None, "Yes", "30 thousand", "One month",
                                          "40 thousand", "Five years", None])
        spoken = [m["message"] for m in backend.messages if m["speaker"] == "ai"]
        self.assertEqual(spoken[3:8], ["Are you working?", "Current CTC?", "Notice period?",
                                       "Expected CTC?", "Years of experience?"])
        self.assertEqual(backend.outcomes, ["completed_questions"])
        self.assertGreater(backend.usages[-1]["transcription"]["audio_input_tokens"], 0)

    async def test_fragmented_amount_and_experience_are_accepted_without_repeating(self):
        backend, _ = await self.run_call(["Yes", "Yes", None, "Yes, I am working", "30 thousand",
                                          "One and half month", ["###", "40,000"],
                                          "Over four and half years", None])
        spoken = [m["message"] for m in backend.messages if m["speaker"] == "ai"]
        self.assertEqual(spoken.count("Expected CTC?"), 1)
        self.assertEqual(spoken.count("Years of experience?"), 1)
        self.assertNotIn("Sorry, I didn't catch that. Could you answer with a number, please?", spoken)
        self.assertEqual(backend.outcomes, ["completed_questions"])

    async def test_short_employment_yes_and_spoken_amount_are_accepted(self):
        backend, _ = await self.run_call(["Yes", "Yes", None, "Definitely", "Thirty thousand",
                                          "One and half month", "Forty thousand", "Four and half years", None])
        spoken = [m["message"] for m in backend.messages if m["speaker"] == "ai"]
        self.assertEqual(spoken.count("Are you working?"), 1)
        self.assertEqual(spoken.count("Expected CTC?"), 1)
        self.assertEqual(backend.outcomes, ["completed_questions"])

    async def test_busy_applicant_confirms_callback_before_scheduling(self):
        backend, _ = await self.run_call(["Yes", "No", "Tomorrow at 2 PM", "Yes", None])
        spoken = [m["message"] for m in backend.messages if m["speaker"] == "ai"]
        self.assertEqual(spoken[:3], ["Am I speaking with Asha?", "Is this a convenient time?",
                                      "When may I call back?"])
        self.assertIn("India time?", spoken[3])
        self.assertEqual(spoken[4], "Your callback is scheduled. Bye.")
        self.assertEqual(len(backend.callbacks), 1)

    async def test_applicant_can_end_call_during_callback_flow(self):
        backend, _ = await self.run_call(["Yes", "No", "Please end the call", None])
        self.assertEqual(backend.outcomes, ["declined_call"])
        self.assertEqual(backend.callbacks, [])
