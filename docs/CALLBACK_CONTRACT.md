# Career result callback contract (DELIVERY-01)

The calling service sends one result event per terminal call attempt after answer extraction completes. The career project must provide a fixed public HTTPS callback URL and a shared `CAREER_CALLBACK_SECRET`. Configure both in the calling service's `backend/.env`. The career project owns the application and final answer tables; this service never writes to that database directly.

## Request

`POST <CAREER_CALLBACK_URL>` with `Content-Type: application/json` and these headers:

| Header | Value |
| --- | --- |
| `X-Career-Event-ID` | Stable UUID for this call result. Same on every retry. |
| `X-Career-Timestamp` | Unix timestamp in seconds, generated for this delivery attempt. |
| `X-Career-Signature` | `sha256=` followed by lowercase hex HMAC-SHA256 over `timestamp + "." + raw request body`, using `CAREER_CALLBACK_SECRET`. |

The receiver must check that the timestamp is within five minutes of its clock, compute the HMAC over the **raw body bytes**, and compare signatures in constant time before parsing or storing the body. Reject an unknown event type, mismatched event ID header/body, or unknown `(source, application_id)`.

Example body (values illustrative):

```json
{
  "event_id": "f523c1d5-ef89-4a89-a698-f6275cd0d18c",
  "event_type": "career_call.result_ready",
  "occurred_at": "2026-11-13 10:42:00",
  "source": "career",
  "application_id": "app-123",
  "request_id": "6e9b8a0c-897b-44e2-a706-740000000001",
  "job_id": "job-45",
  "question_set": {"id": "engineering-screen", "version": "1"},
  "call": {
    "attempt_id": 7,
    "attempt_number": 1,
    "status": "completed",
    "provider_status": "completed",
    "termination_reason": "completed_questions",
    "dialed_at": "2026-11-13 10:38:00",
    "answered_at": "2026-11-13 10:38:20",
    "ended_at": "2026-11-13 10:41:40",
    "duration_seconds": 200
  },
  "answers": [
    {
      "question_id": "experience",
      "status": "answered",
      "answer_text": "five years of experience",
      "evidence_message_id": 48,
      "confidence": "high",
      "review_required": false
    }
  ],
  "transcript_reference": null
}
```

`answers` contains one row for every question in the named version. `answer_text` is an exact applicant transcript quote when status is `answered` or `declined`; it is `null` when `unanswered`. `transcript_reference` is `null` by default. When `CAREER_INCLUDE_TRANSCRIPT_REFERENCE=true`, it is a protected absolute URL; the career project must call it with `CAREER_API_TOKEN` and apply its own retention rules.

## Receiver transaction and acknowledgement

After signature verification, use one database transaction to:

1. Find the existing career application by `(source, application_id)` and verify its stored request/job reference.
2. Insert `event_id` into a receiver event table with a unique constraint. If it already exists with the same payload hash, return HTTP `200` or `204` without applying the answers again. If the same event ID has different content, reject and alert an operator.
3. Upsert the call attempt by `(source, application_id, attempt_id)` and answer rows by `(source, application_id, attempt_id, question_id)` into the main project's tables. Retain status, timestamps, termination reason, confidence, and review flag.
4. Commit, then return `200`, `201`, or `204`. A 2xx response means the result is durable.

Return `408`, `429`, or `5xx` only for temporary failures; the sender retries those and transport errors with exponential backoff, up to `CAREER_DELIVERY_MAX_ATTEMPTS`. Other `4xx` responses are treated as permanent failures requiring investigation. Redirects are not followed. The event ID and JSON body remain stable across retries; the timestamp and signature are regenerated.

## Reconciliation

`GET /api/v1/applications/{application_id}/calls?source=career` uses `Authorization: Bearer <CAREER_API_TOKEN>` and lists attempts, extraction states, event IDs, delivery states, and answers. The optional transcript endpoint is `GET /api/v1/calls/{attempt_id}/transcript` with the same token. The career project can compare its stored event IDs with this list after an outage.
