"""Submodules: environment (ENV-01), conversation (CONVERSATION-01). ASGI entry point."""

from dotenv import load_dotenv
from fastapi import FastAPI, WebSocket

from .scripted_voice_bridge import bridge

load_dotenv()

app = FastAPI(title="Career AI Calling Voice Service")


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok"}


@app.websocket("/api/vobiz/media")
async def vobiz_media(websocket: WebSocket) -> None:
    await bridge(websocket)
