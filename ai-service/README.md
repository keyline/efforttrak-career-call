# Voice service environment

Use Python 3.13 and create a local virtual environment with `python -m venv .venv`. Install dependencies with `.venv\Scripts\python -m pip install -r requirements.txt` on Windows. Copy `.env.example` to `.env` and supply secrets locally.

From this directory, start the development server with:

```powershell
.\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8001
```

`http://127.0.0.1:8001/health` is a startup check. The Vobiz media bridge is at `ws://127.0.0.1:8001/api/vobiz/media` locally; Vobiz needs a public WSS URL in deployment. Set `OPENAI_API_KEY`, `VOBIZ_BRIDGE_TOKEN` (the same value as Laravel), `CAREER_BACKEND_URL`, `OPENAI_REALTIME_MODEL`, and `OPENAI_VOICE` in `.env`. The bridge authenticates a short-lived signed stream URL, loads the call's question version from Laravel, and relays 16 kHz Vobiz audio to OpenAI Realtime at 24 kHz.

For the Surabhi ElevenLabs voice, also set `ELEVENLABS_API_KEY` and its exact `ELEVENLABS_VOICE_ID`. Voice Library access through the API requires an eligible paid ElevenLabs plan. When both values are set, speech uses Eleven v4 at stability 0.5 and similarity 0.75, returning 24 kHz PCM to Vobiz. Without both values, speech uses the existing OpenAI voice. Verify one speech request succeeds before restarting the voice service after changing `.env`.

The bridge posts applicant and AI transcribed turns to Laravel and records `unavailable`, `declined_call`, `completed_questions`, or `time_limit` as the conversation termination reason. Laravel allows a short final-turn window after hangup before structured answer extraction begins.
