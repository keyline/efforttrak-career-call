# Career AI calling module

This repository is being prepared as a standalone Laravel API plus a Python FastAPI voice bridge. See [PLAN.md](PLAN.md) for scope and architecture.

Implemented submodules, their files, and database changes are recorded in [DEVELOPMENT_LOG.md](DEVELOPMENT_LOG.md).

## Production secrets

The Git repository includes production templates in `deploy/env/`. On the server, copy `deploy/env/backend.env.example` to `backend/.env` and `deploy/env/ai-service.env.example` to `ai-service/.env`, then fill the blanks there. Never commit either live file, paste keys into GitHub issues, or expose them through browser code. Limit file access to the service account and administrator. Use a server secret manager if available; GitHub Actions secrets are for deployment jobs, not a substitute for runtime secret storage.

Set `APP_ENV=production`, `APP_DEBUG=false`, production database and public HTTPS/WSS URLs in `backend/.env`. Generate a unique Laravel `APP_KEY` on the server with `php artisan key:generate`. Set the OpenAI key in both services where required, Vobiz credentials in the backend, ElevenLabs credentials in the voice service, and the same random `VOBIZ_BRIDGE_TOKEN` in both services. Keep the career API, callback, Vobiz callback, and operations tokens separate. Run `php artisan config:cache` after changing backend settings and restart the API, queue worker, scheduler, and voice service after secret changes.

Before each first push, review `git status --short`, `git diff --cached --name-only`, and `git check-ignore -v backend/.env ai-service/.env`. Enable GitHub secret scanning and push protection on the repository. If a key was ever shared or committed, revoke it at its provider and issue a new one; removing it from the latest commit does not make the old key safe.

## Local development setup

Requirements: PHP 8.3+, Composer 2, Python 3.13, and MySQL for integration testing. The Laravel scaffold also supports SQLite for local smoke tests.

1. In `backend`, run `composer install`, copy `.env.example` to `.env`, and run `php artisan key:generate`.
2. Set local database and API settings in `backend/.env`; do not commit the file.
3. In `ai-service`, run `python -m venv .venv` and `.venv\Scripts\python -m pip install -r requirements.txt`.
4. Copy `ai-service/.env.example` to `ai-service/.env` and supply the OpenAI/Vobiz bridge settings locally.

The module now contains intake, Vobiz callbacks, the voice conversation, answer extraction, and result sending. The career project's callback receiver and a live end-to-end test are still required before enabling automatic calling.

## Application intake API

Set a long random `CAREER_API_TOKEN` in `backend/.env`, then run `php artisan migrate` from `backend`. The career project sends a JSON request to `POST /api/v1/applications` with `Authorization: Bearer <CAREER_API_TOKEN>`:

```json
{
  "source": "careers-site",
  "application_id": "app-123",
  "job_id": "job-45",
  "job_title": "Software Engineer",
  "applicant_name": "Asha Shah",
  "phone": "+919876543210",
  "language": "en-IN",
  "question_set_id": "engineering-screen",
  "question_set_version": "1",
  "consent": {
    "granted": true,
    "captured_at": "2026-11-13T09:00:00+05:30",
    "method": "career_form_checkbox",
    "text_version": "2026-11"
  },
  "submitted_at": "2026-11-13T09:00:01+05:30"
}
```

`source` defaults to `career`. The API returns `201` with `request_id` and `state` on first receipt; an identical retry returns `200` with the same values. Reusing `(source, application_id)` with different data returns `409`. Invalid input returns `422`, and missing or incorrect authentication returns `401`. Consent not granted is stored as `consent_missing`; no call is queued. The `queued` state means intake succeeded and a dispatch job was queued; it does not mean a call has started.

## Call dispatch

`DISPATCH-01` adds a queued Vobiz dial job, call policy checks, attempt records, answer/hangup callbacks, and a scheduled scan for due calls. The intake payload may include `"do_not_call": true`; such an application is stored as `do_not_call` and is never queued. This flag reflects the career project's state at submission time.

Set the `VOBIZ_*` values and call policy values in `backend/.env` from `.env.example`. Run `php artisan migrate`, then keep these processes running from `backend`:

```powershell
php artisan queue:work --queue=default
php artisan schedule:work
```

Configure Vobiz to reach the public HTTPS `VOBIZ_ANSWER_URL` (`/api/vobiz/answer`) and `VOBIZ_HANGUP_URL` (`/api/vobiz/hangup`). The `VOBIZ_STREAM_URL` must be public WSS. Vobiz callback URLs receive a separate `VOBIZ_CALLBACK_TOKEN`, and the media URL receives a short-lived HMAC signature made with `VOBIZ_BRIDGE_TOKEN`.

`CALL_DISPATCH_ENABLED` and `CAREER_VOICE_READY` both default to `false`. Leave `CAREER_VOICE_READY=false` until the media bridge and recruitment conversation module are live-tested; otherwise Vobiz could connect a candidate to an unavailable stream. Once enabled, the worker checks consent, do-not-call, submission age, local days/hours, per-phone cooldown, hourly call limit, and the maximum attempts. Busy and no-answer results can be retried after `CALL_RETRY_MINUTES`. Ambiguous Vobiz dial errors are recorded as failed for manual review to avoid a duplicate call. Structured answers are extracted after terminal calls and prepared for callback delivery.

## Recruitment conversation

Publish an immutable question version before submitting applications that reference it. `POST /api/v1/question-sets` uses the same career API Bearer token as application intake:

```json
{
  "source": "career",
  "question_set_id": "engineering-screen",
  "version": "1",
  "questions": [
    {"id": "experience", "text": "How many years of relevant experience do you have?"},
    {"id": "notice", "text": "What is your notice period?"}
  ]
}
```

There must be 1–10 questions with distinct IDs. Reposting the same version and content returns `200`; changing a published version returns `409`, so create a new version for edits. A call with an unknown question version enters `question_set_missing` and waits for publication. The voice bridge loads the matching version over a protected Laravel endpoint, discloses that it is an AI recruitment assistant, and checks availability. It reveals one question at a time to the voice model, advancing only after a validated tool call for the current question; a declined question can be skipped. Transcribed turns are stored for later answer extraction. Call duration is bounded by `CALL_TIME_LIMIT_SECONDS` in both the Vobiz request and the bridge.

Keep `CALL_DISPATCH_ENABLED=false` and `CAREER_VOICE_READY=false` until the Laravel API and FastAPI bridge are deployed with working public HTTPS/WSS URLs and a consented end-to-end test call succeeds. The question flow has automated tests but has not been verified with live Vobiz and OpenAI audio. Implement and test the callback receiver in the main project before enabling delivery.

## Structured answers

`ANSWERS-01` extracts one result per published question after a call ends. Each result is `answered`, `declined`, or `unanswered`. Answer text is an exact quote from an applicant transcript turn, with that turn's ID as evidence, a confidence level, and a review flag. Unsupported quotes or question IDs fail the whole extraction and save no partial results. A call without applicant speech produces unanswered results without an OpenAI request.

Set `OPENAI_API_KEY` in `backend/.env` to the same account credential used by the voice service. `ANSWER_EXTRACTION_MODEL` defaults to `gpt-4o-mini`; `ANSWER_EXTRACTION_MAX_RUNS` defaults to 3. The queue worker runs extraction 35 seconds after the hangup callback to allow the 30-second final-transcript window to close, and the scheduler scans for missing or retryable failed runs. Failed runs retain an error code for investigation.

The career project can read `GET /api/v1/calls/{attempt_id}/answers` with its `CAREER_API_TOKEN`. The response includes the application reference, question-set version, call status, dial/answer/end times, duration, termination reason, extraction status, and ordered answers. Implement and test storage and access rules in the main project before enabling callback delivery.

## Result delivery

`DELIVERY-01` snapshots a result after extraction completes and posts it to `CAREER_CALLBACK_URL` over HTTPS. Each event has a stable UUID, the source application and request IDs, call outcome, question-set version, and ordered answers. The request is signed with `CAREER_CALLBACK_SECRET`; transient failures retry with exponential backoff. Set `CAREER_INCLUDE_TRANSCRIPT_REFERENCE=true` only if the career project needs transcript access. The protected `GET /api/v1/applications/{application_id}/calls` endpoint supports reconciliation.

The exact payload, signature algorithm, acknowledgement rules, and receiver upsert requirements are in [docs/CALLBACK_CONTRACT.md](docs/CALLBACK_CONTRACT.md). The receiver must be implemented in the career project and tested before configuring the callback URL. Until then, leave `CAREER_CALLBACK_URL` and `CAREER_CALLBACK_SECRET` unset; prepared deliveries remain `configuration_blocked` and can be sent after configuration is added. Keep live calling disabled until the end-to-end integration and consented audio pilot succeed.

## Operations API

Set a separate long random `OPERATIONS_API_TOKEN` in `backend/.env` and run `php artisan migrate`. Send `Authorization: Bearer <OPERATIONS_API_TOKEN>` to the protected endpoints below. The career API token cannot access them. The endpoints return paginated JSON and omit phone numbers, transcripts, and stored delivery payloads.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/operations/calls?status=failed` | Call, extraction, delivery, and review status. The status filter is optional. |
| `GET` | `/api/v1/operations/deliveries?status=failed_exhausted` | Failed deliveries by default; optional status filter. |
| `POST` | `/api/v1/operations/deliveries/{id}/retry` | Requeue a failed or blocked delivery using its existing event ID and payload. |
| `POST` | `/api/v1/operations/calls/{id}/retry-extraction` | Requeue a failed answer extraction. |
| `GET` | `/api/v1/operations/question-sets?source=career` | List published question-set versions. |
| `POST` | `/api/v1/operations/question-sets` | Publish an immutable question-set version using the JSON format above. |
| `GET` | `/api/v1/operations/audit` | Review operator actions and system outcome events. |

Retries require a running queue worker. Only eligible failed states can be retried; an in-progress or successful item returns `409`. Question-set publication is idempotent. The API is rate limited to 30 requests per minute per client, and audit details contain only IDs, statuses, counts, and error codes. Keep the operations token in secret storage or environment configuration and do not put it in a browser page or client application.
# efforttrak-career-call
