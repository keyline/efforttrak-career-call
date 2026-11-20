"""Script-controlled Vobiz call: Realtime transcribes, exact script text is spoken via TTS."""

import asyncio
import base64
import hashlib
import hmac
import json
import logging
import os
import re
import time
from datetime import datetime, timedelta, timezone
from urllib.parse import quote, urlencode

import httpx
from fastapi import WebSocket
from websockets.asyncio.client import connect

from .voice_bridge import availability_answer, resample_16k_to_24k

logger = logging.getLogger(__name__)
IST = timezone(timedelta(hours=5, minutes=30))


def authorized_stream(attempt_id: str, expires: str, signature: str) -> bool:
    secret = os.getenv("VOBIZ_BRIDGE_TOKEN", "")
    if not secret or not attempt_id.isdecimal() or not expires.isdecimal():
        return False
    if not 0 <= int(expires) - time.time() <= 300:
        return False
    expected = hmac.new(secret.encode(), f"{attempt_id}.{expires}".encode(), hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature)


def is_greeting(text: str) -> bool:
    return bool(re.fullmatch(r"(?i)\s*(hello|hi|hey|can you hear me|are you there)[.!? ]*", text))


def has_substantive_answer(text: str, question_id: str) -> bool:
    if not text.strip() or is_greeting(text) or text.strip().lower() in {"yes", "no", "okay", "ok"}:
        return False
    if question_id in {"q2", "q3", "q4", "q5"}:
        return bool(re.search(r"\d|\b(one|two|three|four|five|six|seven|eight|nine|ten|twenty|thirty|forty|fifty|hundred|half|month|year|week|lakh|thousand|immediate|negotiable)\b", text, re.I))
    return True


async def synthesize_speech(client: httpx.AsyncClient, text: str, openai_key: str) -> bytes:
    eleven_key = os.getenv("ELEVENLABS_API_KEY", "").strip()
    eleven_voice = os.getenv("ELEVENLABS_VOICE_ID", "").strip()
    if eleven_key and eleven_voice:
        response = await client.post(
            "https://api.elevenlabs.io/v1/text-to-speech/" + quote(eleven_voice, safe=""),
            params={"output_format": "pcm_24000"},
            headers={"xi-api-key": eleven_key},
            json={"text": text, "model_id": "eleven_v4", "language_code": "en",
                  "voice_settings": {"stability": 0.5, "similarity_boost": 0.75}},
            timeout=60,
        )
    else:
        response = await client.post(
            "https://api.openai.com/v1/audio/speech",
            headers={"Authorization": f"Bearer {openai_key}"},
            json={"model": os.getenv("OPENAI_TTS_MODEL", "gpt-4o-mini-tts"),
                  "voice": os.getenv("OPENAI_VOICE", "marin"),
                  "input": text, "response_format": "pcm", "speed": 0.96,
                  "instructions": "Speak English with a natural, light Indian accent throughout. Sound like a warm human recruiter on a phone call, with conversational rhythm and varied intonation. Keep the accent consistent, avoid exaggerated pronunciation, and do not switch languages."},
        )
    response.raise_for_status()
    audio = response.content
    if not audio or len(audio) % 2:
        raise ValueError("Speech provider did not return valid PCM audio")
    return audio


async def bridge(websocket: WebSocket) -> None:
    attempt_id = websocket.query_params.get("attempt_id", "")
    if not authorized_stream(attempt_id, websocket.query_params.get("expires", ""), websocket.query_params.get("signature", "")):
        await websocket.close(code=1008)
        return
    api_key = os.getenv("OPENAI_API_KEY", "").strip()
    token = os.getenv("VOBIZ_BRIDGE_TOKEN", "").strip()
    backend_url = os.getenv("CAREER_BACKEND_URL", "http://127.0.0.1:8002").rstrip("/")
    if not api_key:
        await websocket.close(code=1011)
        return
    async with httpx.AsyncClient(base_url=backend_url, timeout=20) as backend, httpx.AsyncClient(timeout=30) as openai:
        headers = {"Authorization": f"Bearer {token}"}
        response = await backend.get(f"/api/vobiz/internal/attempts/{attempt_id}", headers=headers)
        response.raise_for_status()
        context = response.json()
        questions = context["questions"]
        if not questions or not context.get("introduction") or not context.get("conclusion"):
            await websocket.close(code=1011)
            return
        stream_id = None
        stage = "identity" if context.get("availability_question") else "intro"
        current_text = context["introduction"]
        question_index = -1
        unclear_replies = 0
        skipped_answer = False
        working = False
        audio_played = False
        next_marker = 0
        active_marker = None
        pending_speech: list[str] = []
        scheduled_at: datetime | None = None
        goodbye_task: asyncio.Task | None = None
        unclear_task: asyncio.Task | None = None
        finished = asyncio.Event()
        usage = {
            "transcription": {"model": "gpt-4o-transcribe", "input_tokens": 0, "output_tokens": 0,
                              "audio_input_tokens": 0, "seconds": 0},
            "callback": {"model": os.getenv("OPENAI_CALLBACK_MODEL", "gpt-4o-mini"), "input_tokens": 0, "output_tokens": 0},
            "elevenlabs": {"model": "eleven_v4", "characters": 0, "requests": 0},
        }

        async def report_usage() -> None:
            try:
                result = await backend.post(f"/api/vobiz/internal/attempts/{attempt_id}/usage", headers=headers, json=usage)
                result.raise_for_status()
            except httpx.HTTPError:
                logger.exception("Could not save usage for attempt %s", attempt_id)

        async def save_message(speaker: str, message: str) -> None:
            if message.strip():
                for retry in range(3):
                    try:
                        result = await backend.post(f"/api/vobiz/internal/attempts/{attempt_id}/messages", headers=headers,
                                                    json={"speaker": speaker, "message": message[:10000]})
                        result.raise_for_status()
                        return
                    except httpx.HTTPError:
                        logger.warning("Transcript save retry %s for attempt %s", retry + 1, attempt_id)
                        await asyncio.sleep(0.2 * (retry + 1))

        async def save_outcome(reason: str) -> None:
            try:
                result = await backend.post(f"/api/vobiz/internal/attempts/{attempt_id}/outcome", headers=headers,
                                            json={"reason": reason})
                result.raise_for_status()
            except httpx.HTTPError:
                logger.exception("Could not save outcome for attempt %s", attempt_id)

        async def hangup() -> None:
            if finished.is_set():
                return
            call_id = context.get("provider_call_id")
            auth_id = os.getenv("VOBIZ_AUTH_ID", "").strip()
            auth_token = os.getenv("VOBIZ_AUTH_TOKEN", "").strip()
            if not all((call_id, auth_id, auth_token)):
                logger.error("Cannot hang up attempt %s: missing Vobiz call identity", attempt_id)
                return
            await report_usage()
            try:
                result = await openai.delete(f"https://api.vobiz.ai/api/v1/Account/{auth_id}/Call/{call_id}/",
                                             headers={"X-Auth-ID": auth_id, "X-Auth-Token": auth_token})
                result.raise_for_status()
                finished.set()
            except httpx.HTTPError:
                logger.exception("Vobiz hangup failed for attempt %s", attempt_id)

        async def say(text: str) -> None:
            nonlocal current_text, next_marker, active_marker, audio_played, goodbye_task
            if not stream_id:
                return
            current_text = text
            audio_played = False
            next_marker += 1
            active_marker = f"career-{attempt_id}-{next_marker}"
            async def send_audio(chunk: bytes) -> None:
                await websocket.send_json({"event": "playAudio", "streamId": stream_id,
                                           "media": {"contentType": "audio/x-l16", "sampleRate": 24000,
                                                     "payload": base64.b64encode(chunk).decode()}})
            audio_size = 0
            eleven_key = os.getenv("ELEVENLABS_API_KEY", "").strip()
            eleven_voice = os.getenv("ELEVENLABS_VOICE_ID", "").strip()
            if eleven_key and eleven_voice:
                async with openai.stream("POST", "https://api.elevenlabs.io/v1/text-to-speech/" + quote(eleven_voice, safe="") + "/stream",
                    params={"output_format": "pcm_24000"}, headers={"xi-api-key": eleven_key},
                    json={"text": text, "model_id": "eleven_v4", "language_code": "en",
                          "voice_settings": {"stability": 0.5, "similarity_boost": 0.75}}, timeout=60) as response:
                    response.raise_for_status()
                    buffer = b""
                    async for chunk in response.aiter_bytes():
                        buffer += chunk
                        while len(buffer) >= 9600:
                            await send_audio(buffer[:9600])
                            audio_size += 9600
                            buffer = buffer[9600:]
                    if buffer:
                        if len(buffer) % 2:
                            raise ValueError("Speech provider returned invalid PCM audio")
                        await send_audio(buffer)
                        audio_size += len(buffer)
                if audio_size:
                    usage["elevenlabs"]["characters"] += len(text)
                    usage["elevenlabs"]["requests"] += 1
            else:
                audio = await synthesize_speech(openai, text, api_key)
                audio_size = len(audio)
                for offset in range(0, len(audio), 9600):
                    await send_audio(audio[offset:offset + 9600])
            await websocket.send_json({"event": "checkpoint", "streamId": stream_id, "name": active_marker})
            if stage == "goodbye":
                async def fallback() -> None:
                    await asyncio.sleep(max(8, audio_size / 48000 + 4))
                    await hangup()
                goodbye_task = asyncio.create_task(fallback())

        def next_question() -> str | None:
            nonlocal question_index
            question_index += 1
            while question_index < len(questions) and questions[question_index].get("ask_if") == "q1_yes" and not working:
                question_index += 1
            return questions[question_index]["text"] if question_index < len(questions) else None

        async def parse_callback(text: str) -> datetime | None:
            now_ist = datetime.now(IST)
            prompt = ("Extract the applicant's requested callback date and time in Asia/Kolkata. "
                      "Return JSON with requested_at as ISO 8601 with +05:30, or null if unclear. "
                      "Do not invent an unspecified date or time. Current India time: " + now_ist.isoformat() +
                      ". Applicant said: " + json.dumps(text, ensure_ascii=False))
            try:
                result = await openai.post("https://api.openai.com/v1/chat/completions",
                                           headers={"Authorization": f"Bearer {api_key}"},
                                           json={"model": os.getenv("OPENAI_CALLBACK_MODEL", "gpt-4o-mini"),
                                                 "response_format": {"type": "json_object"},
                                                 "messages": [{"role": "user", "content": prompt}]})
                result.raise_for_status()
                callback_usage = result.json().get("usage") or {}
                usage["callback"]["input_tokens"] += int(callback_usage.get("prompt_tokens", 0))
                usage["callback"]["output_tokens"] += int(callback_usage.get("completion_tokens", 0))
                value = json.loads(result.json()["choices"][0]["message"]["content"])["requested_at"]
                when = datetime.fromisoformat(value) if value else None
                if when and when.utcoffset() == timedelta(hours=5, minutes=30) and now_ist + timedelta(minutes=1) < when < now_ist + timedelta(days=90):
                    return when
            except (httpx.HTTPError, ValueError, KeyError, TypeError):
                logger.exception("Could not parse callback time for attempt %s", attempt_id)
            return None

        async def on_answer(text: str) -> None:
            nonlocal stage, working, scheduled_at, unclear_replies, skipped_answer, unclear_task
            if unclear_task:
                unclear_task.cancel()
                unclear_task = None
            if stage in {"identity", "availability", "intro", "question", "callback_time", "callback_confirm"} and re.search(r"(?i)\b(stop calling|end the call|hang up|goodbye|do not call|don't call)\b", text):
                await save_outcome("declined_call")
                stage = "goodbye"
                await say(context.get("callback_declined") or "Thank you for your time. Bye.")
            elif stage == "identity":
                answer = availability_answer(text)
                if answer == "yes" or re.search(r"(?i)\b(speaking|this is (she|he|me))\b", text):
                    stage = "availability"
                    await say(context["availability_question"])
                elif answer == "no" or re.search(r"(?i)\b(wrong person|wrong number)\b", text):
                    await save_outcome("wrong_person")
                    stage = "goodbye"
                    await say("I'm sorry for the inconvenience. Thank you for your time. Bye.")
                else:
                    await say(context["introduction"])
            elif stage == "availability":
                answer = availability_answer(text)
                if answer == "yes":
                    stage = "statement"
                    await say(context["introduction_statement"])
                elif answer == "no":
                    stage = "callback_time"
                    await say(context["unavailable"])
                else:
                    await say(context["availability_question"])
            elif stage == "intro":
                answer = availability_answer(text)
                if answer == "yes":
                    stage = "question"
                    await say(next_question())
                elif answer == "no":
                    stage = "callback_time"
                    match = re.search(r"ask:\s*(.+?\?)", context.get("unavailable") or "", re.I)
                    await say(match.group(1) if match else "Which date and time, in India time, will you be available for a callback?")
                elif not is_greeting(text):
                    await say("May I speak with you now?")
                else:
                    await say("May I speak with you now?")
            elif stage == "question":
                if re.search(r"(?i)\b(call me later|not a good time|in a meeting|can't talk|cannot talk)\b", text):
                    stage = "callback_time"
                    await say(context.get("unavailable") or "Which date and time will you be available for a callback?")
                    return
                question = questions[question_index]
                if question_index == 0:
                    answer = availability_answer(text)
                    if answer == "unclear" and re.search(r"(?i)\b(definitely|currently working|i am working)\b", text):
                        answer = "yes"
                    if answer not in ("yes", "no"):
                        return
                    working = answer == "yes"
                elif not has_substantive_answer(text, question["id"]):
                    async def retry_if_still_unclear() -> None:
                        nonlocal unclear_replies, skipped_answer, stage
                        await asyncio.sleep(3)
                        if stage != "question":
                            return
                        unclear_replies += 1
                        if unclear_replies < 2:
                            await say("Sorry, I didn't catch that. Could you answer with a number, please?")
                        else:
                            skipped_answer = True
                            next_text = next_question()
                            if next_text:
                                await say(next_text)
                            else:
                                await save_outcome("incomplete")
                                stage = "goodbye"
                                await say(context["conclusion"])
                    unclear_task = asyncio.create_task(retry_if_still_unclear())
                    return
                unclear_replies = 0
                next_text = next_question()
                if next_text:
                    await say(next_text)
                else:
                    await save_outcome("incomplete" if skipped_answer else "completed_questions")
                    stage = "goodbye"
                    await say(context["conclusion"])
            elif stage == "callback_time":
                if re.search(r"(?i)\b(do not call|don't call|no callback|never mind)\b", text):
                    await save_outcome("declined_call")
                    stage = "goodbye"
                    await say(context.get("callback_declined") or "Thank you. Bye.")
                    return
                scheduled_at = await parse_callback(text)
                if not scheduled_at:
                    await say(current_text)
                    return
                stage = "callback_confirm"
                template = context.get("callback_confirmation") or "You are available on {callback_date} at {callback_time} India time. Is that correct?"
                await say(template.replace("{callback_date}", scheduled_at.strftime("%d %B %Y"))
                                  .replace("{callback_time}", scheduled_at.strftime("%I:%M %p")))
            elif stage == "callback_confirm":
                answer = availability_answer(text)
                if answer == "yes" and scheduled_at:
                    try:
                        result = await backend.post(f"/api/vobiz/internal/attempts/{attempt_id}/callback",
                                                    headers=headers, json={"requested_at": scheduled_at.isoformat(timespec="seconds")})
                        result.raise_for_status()
                        stage = "goodbye"
                        await say(context.get("callback_scheduled") or "Thank you. I will call you then. Bye.")
                    except httpx.HTTPError:
                        logger.exception("Could not schedule callback for attempt %s", attempt_id)
                        stage = "callback_time"
                        await say(context.get("unavailable") or "Which date and time, in India time, will you be available for a callback?")
                elif answer == "no":
                    stage = "callback_time"
                    await say(context.get("unavailable") or "Which date and time, in India time, will you be available for a callback?")
                else:
                    await say(current_text)

        model = os.getenv("OPENAI_REALTIME_MODEL", "gpt-realtime")
        async with connect("wss://api.openai.com/v1/realtime?" + urlencode({"model": model}),
                           additional_headers={"Authorization": f"Bearer {api_key}"}, max_size=16 * 1024 * 1024) as upstream:
            await upstream.send(json.dumps({"type": "session.update", "session": {
                "type": "realtime", "audio": {"input": {
                    "format": {"type": "audio/pcm", "rate": 24000},
                    "transcription": {"model": "gpt-4o-transcribe", "language": "en"},
                    "turn_detection": {"type": "server_vad", "create_response": False, "interrupt_response": False,
                                       "silence_duration_ms": 500},
                }},
            }}))
            await websocket.accept()

            async def vobiz_events() -> None:
                nonlocal stream_id, audio_played, stage
                async for raw in websocket.iter_text():
                    event = json.loads(raw)
                    kind = event.get("event")
                    if kind == "start":
                        start = event.get("start", {})
                        fmt = start.get("mediaFormat", {})
                        if fmt.get("encoding") != "audio/x-l16" or int(fmt.get("sampleRate", 0)) != 16000:
                            raise ValueError("Expected Vobiz 16 kHz PCM input")
                        stream_id = start.get("streamId")
                        if not stream_id:
                            raise ValueError("Missing Vobiz stream ID")
                        await say(context["introduction"])
                    elif kind == "media" and stream_id:
                        payload = resample_16k_to_24k(base64.b64decode(event["media"]["payload"]))
                        if payload:
                            await upstream.send(json.dumps({"type": "input_audio_buffer.append", "audio": base64.b64encode(payload).decode()}))
                    elif kind == "playedStream" and event.get("name") == active_marker:
                        audio_played = True
                        await save_message("ai", current_text)
                        if stage == "goodbye":
                            if goodbye_task:
                                goodbye_task.cancel()
                            await hangup()
                            return
                        if stage == "statement":
                            stage = "question"
                            pending_speech.clear()
                            await say(next_question())
                            continue
                        if pending_speech:
                            early = pending_speech[:]
                            pending_speech.clear()
                            for text in early:
                                if not is_greeting(text) and text.casefold().strip(" .?!") != current_text.casefold().strip(" .?!"):
                                    marker_before = active_marker
                                    await on_answer(text)
                                    if active_marker != marker_before:
                                        break
                    elif kind == "stop":
                        return

            async def realtime_events() -> None:
                async for raw in upstream:
                    event = json.loads(raw)
                    kind = event.get("type")
                    if kind == "conversation.item.input_audio_transcription.completed":
                        event_usage = event.get("usage") or {}
                        usage["transcription"]["input_tokens"] += int(event_usage.get("input_tokens", 0))
                        usage["transcription"]["output_tokens"] += int(event_usage.get("output_tokens", 0))
                        usage["transcription"]["audio_input_tokens"] += int((event_usage.get("input_token_details") or {}).get("audio_tokens", 0))
                        usage["transcription"]["seconds"] += float(event_usage.get("seconds", 0))
                        text = event.get("transcript", "").strip()
                        if not text or text in {"###", "context:", "audio."}:
                            continue
                        await save_message("applicant", text)
                        if audio_played and stage != "goodbye":
                            await on_answer(text)
                        elif not audio_played:
                            pending_speech.append(text)
                    elif kind == "error":
                        logger.error("OpenAI realtime transcription error for attempt %s: %s", attempt_id, event.get("error", {}))

            tasks = [asyncio.create_task(vobiz_events()), asyncio.create_task(realtime_events())]
            done, pending = await asyncio.wait(tasks, timeout=max(30, min(1800, int(context.get("max_duration_seconds", 300)))),
                                               return_when=asyncio.FIRST_COMPLETED)
            if not done:
                await save_outcome("time_limit")
            for task in pending:
                task.cancel()
            await asyncio.gather(*pending, return_exceptions=True)
            if goodbye_task:
                goodbye_task.cancel()
            if unclear_task:
                unclear_task.cancel()
            await report_usage()
            for task in done:
                task.result()
    try:
        await websocket.close()
    except RuntimeError:
        pass
