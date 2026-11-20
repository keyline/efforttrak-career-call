"""Speech provider request contract for the Vobiz PCM bridge."""

import json
import os
import unittest
from unittest.mock import patch

import httpx

from app.scripted_voice_bridge import synthesize_speech


class SpeechTests(unittest.IsolatedAsyncioTestCase):
    async def test_surabhi_settings_and_pcm_format(self) -> None:
        requests = []

        def respond(request: httpx.Request) -> httpx.Response:
            requests.append(request)
            return httpx.Response(200, content=b"\0\0")

        with patch.dict(os.environ, {"ELEVENLABS_API_KEY": "test-key", "ELEVENLABS_VOICE_ID": "surabhi-id"}):
            async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
                self.assertEqual(await synthesize_speech(client, "Hello", "openai-key"), b"\0\0")

        request = requests[0]
        self.assertEqual(request.url.path, "/v1/text-to-speech/surabhi-id")
        self.assertEqual(request.url.params["output_format"], "pcm_24000")
        self.assertEqual(json.loads(request.content), {
            "text": "Hello", "model_id": "eleven_v4", "language_code": "en",
            "voice_settings": {"stability": 0.5, "similarity_boost": 0.75},
        })

    async def test_openai_remains_available_without_elevenlabs_configuration(self) -> None:
        requests = []

        def respond(request: httpx.Request) -> httpx.Response:
            requests.append(request)
            return httpx.Response(200, content=b"\0\0")

        with patch.dict(os.environ, {"ELEVENLABS_API_KEY": "", "ELEVENLABS_VOICE_ID": ""}):
            async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
                await synthesize_speech(client, "Hello", "openai-key")

        self.assertEqual(requests[0].url.path, "/v1/audio/speech")
