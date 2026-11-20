"""Submodule: conversation (CONVERSATION-01). Voice bridge policy and authentication tests."""

import hashlib
import hmac
import os
import time
import unittest
from unittest.mock import patch

from app.voice_bridge import ScreeningFlow, authorized_stream, resample_16k_to_24k, screening_instructions


class ConversationTests(unittest.TestCase):
    def test_instructions_cover_disclosure_order_refusal_and_job(self) -> None:
        instructions = screening_instructions({
            "applicant_name": "Asha", "job_title": "Engineer", "language": "en-IN",
            "question_set_id": "engineering", "question_set_version": "1",
            "questions": [
                {"id": "experience", "text": "How many years of experience?"},
                {"id": "notice", "text": "What is your notice period?"},
            ],
        })
        for phrase in ("AI recruitment assistant", "good time", "one screening question at a time", "declined question", "asks to stop", "Engineer"):
            self.assertIn(phrase, instructions)
        self.assertIn("begin_screening", instructions)
        self.assertNotIn("What is your notice period?", instructions)

    def test_flow_reveals_questions_in_order_and_allows_a_decline(self) -> None:
        flow = ScreeningFlow([
            {"id": "experience", "text": "How many years of experience?"},
            {"id": "notice", "text": "What is your notice period?"},
        ])
        first, prompt = flow.handle("begin_screening", {})
        self.assertEqual(first["question_id"], "experience")
        self.assertNotIn("notice period", prompt)
        wrong, _ = flow.handle("advance_question", {"question_id": "notice", "result": "answered"})
        self.assertEqual(wrong["error"], "wrong_question")
        second, prompt = flow.handle("advance_question", {"question_id": "experience", "result": "declined"})
        self.assertEqual(second["question_id"], "notice")
        self.assertIn("notice period", prompt)
        done, _ = flow.handle("advance_question", {"question_id": "notice", "result": "answered"})
        self.assertEqual(done["status"], "completed")

    def test_flow_can_stop_before_questions(self) -> None:
        flow = ScreeningFlow([{"id": "experience", "text": "Experience?"}])
        ended, prompt = flow.handle("end_screening", {})
        self.assertEqual(ended["status"], "ended")
        self.assertIn("close", prompt)

    def test_stream_signature_expires_and_rejects_tampering(self) -> None:
        expires = str(int(time.time()) + 120)
        signature = hmac.new(b"secret", f"4.{expires}".encode(), hashlib.sha256).hexdigest()
        with patch.dict(os.environ, {"VOBIZ_BRIDGE_TOKEN": "secret"}):
            self.assertTrue(authorized_stream("4", expires, signature))
            self.assertFalse(authorized_stream("5", expires, signature))
            self.assertFalse(authorized_stream("4", str(int(time.time()) - 1), signature))

    def test_resampling_produces_24k_pcm(self) -> None:
        self.assertEqual(len(resample_16k_to_24k(b"\x00\x00" * 160)), 480)


if __name__ == "__main__":
    unittest.main()
