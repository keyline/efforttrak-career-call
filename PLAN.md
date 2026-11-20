# Career application AI calling: scope and plan

## Goal

When the career project's application is saved, it submits an authenticated event to this service. The service calls the applicant through Vobiz, asks a short job-specific screening questionnaire using OpenAI voice, and sends structured answers and call status back to the career project. The career project owns the applicant record and stores the final answers in its database.

## Existing assets and design choice

`D:\AI-sales-CRM` already has a Laravel Vobiz outbound Call API integration, Vobiz answer/hangup callbacks, a FastAPI WebSocket media bridge to OpenAI Realtime, and call transcript storage. Reuse its integration pattern and configuration values, but keep this module's applicants, calls, prompts, API tokens, and callbacks separate from CRM sales data. The current CRM outbound opening is sales-specific, so the career agent needs its own recruitment prompt and question flow. Do not copy live secrets into source control.

## Technologies to use

| Component | Technology | Purpose |
| --- | --- | --- |
| API and call orchestration | PHP 8.3+ with Laravel 12 | Authenticated application intake, Vobiz callbacks, call state, result API, and webhook delivery. The existing CRM is Laravel 11; this new service uses a current patched release. |
| Background processing | Laravel queues and scheduler | Dispatch calls after application intake; retry failed calls and result deliveries. Queue backend to be selected for deployment (database initially; Redis if volume requires it). |
| Voice bridge | Python 3 with FastAPI, Uvicorn, and WebSockets | Relay Vobiz live audio to OpenAI over a public WSS endpoint. Adapt the CRM's existing bridge. |
| Telephony | Vobiz outbound Call API and bidirectional Stream | Place calls, receive answer/hangup callbacks, and stream audio. Use the same account credentials as the CRM through server-side configuration. |
| AI voice and answer extraction | OpenAI Realtime API; OpenAI Responses API for structured extraction | Conduct the conversation and turn the transcript into validated per-question answers. Use the same OpenAI API credential, stored only on the server. |
| Module database | MySQL with Laravel migrations | Store application references, call attempts, question-set versions, transcript, answers, and delivery attempts. Keep it separate from the career project's database. |
| Project integration | HTTPS JSON REST APIs, signed webhooks, and idempotency keys | Receive new application events and return results reliably to the career project. |
| Security and deployment | TLS/HTTPS, WSS, environment-managed secrets, Laravel authentication, and HMAC signatures | Secure inbound requests, provider callbacks, media access, and result delivery. Run the Python bridge as an ASGI service. |
| Verification | PHPUnit for Laravel; pytest for FastAPI | Check API contracts, state transitions, duplicate events, answer extraction, and delivery retries. |

An admin interface can be added with Laravel views or a separate frontend once the career project's UI requirements are known. The first release can expose protected operational APIs without committing to a frontend framework.

## In scope for first release

1. **Application intake API.** `POST /api/v1/applications` accepts `application_id`, `job_id`, job title, applicant name, E.164 phone, language, screening question-set identifier/version, consent evidence, and submission time. Authenticate server-to-server requests. Return a stable `request_id` and state. A unique `(source, application_id)` key makes retries safe.
2. **Call dispatch.** Queue the call after validation; check consent, do-not-call state, allowed local hours, and rate limits. Use Vobiz with the existing account/caller credentials. Track queued, dialing, answered, completed, no-answer, busy, failed, and cancelled states. Limit attempts and make retries configurable.
3. **Conversation.** Disclose that the caller is an AI recruitment assistant, confirm the applicant can talk, identify the job, ask the configured questions one at a time, allow clarification and refusal, then close politely. Put a maximum duration on calls. Question sets are versioned so old results retain their meaning.
4. **Answers.** Save a transcript and extract one structured result per question: question ID, answer text, answered/declined/unanswered state, and confidence or review flag. Preserve the raw applicant wording; do not invent missing answers. Include call timestamps and termination reason.
5. **Result delivery.** `POST` a signed completion payload to the career project's callback URL. Include `application_id`, `request_id`, event ID, call status, question-set version, answers, and transcript reference if enabled. Retry transient failures with backoff; the career project upserts by event ID and stores the answers in its own database. Offer `GET /api/v1/applications/{application_id}/calls` for reconciliation.
6. **Operations.** Minimal admin view or protected endpoints for call/result status, failed deliveries, manual retry, question-set configuration, and audit trail. Keep secrets in environment or secret storage; redact phone numbers and transcripts from logs.

## API and ownership boundary

Career project: owns jobs, applications, applicant consent, and final answer tables. It triggers intake only after committing an application, authenticates the callback, and records delivery idempotently. This service: owns call orchestration, provider IDs, transcript, extraction, retry state, and callback delivery. Vobiz and OpenAI credentials stay server-side here. No direct cross-project database writes.

## Implementation sequence

1. Confirm the career project's stack, application save hook, callback endpoint, question list/languages, consent wording, call window, and retry policy.
2. Scaffold this service with configuration, database schema, secret management, health check, and authenticated intake API.
3. Adapt the CRM Vobiz/OpenAI call pipeline for a recruitment agent and versioned questions. Ensure provider callbacks and media bridge can reach the deployed service over HTTPS/WSS.
4. Implement transcript capture, structured extraction with validation, result payload, signed webhook delivery, and reconciliation endpoint.
5. Integrate the career project trigger and answer storage; test both sides with duplicate application events and duplicate/out-of-order provider callbacks.
6. Run a limited live pilot using a consented test applicant, verify audio, answers, delivery, failure handling, and costs before enabling automatic calling.

## Acceptance criteria

- One saved, eligible application creates at most one initial call despite repeated intake requests.
- Ineligible or non-consenting applications never dial and receive a clear state/reason.
- The agent asks only the selected question-set version; every question has a result state, including unanswered.
- The career database receives answers for the correct application exactly once in effect, even when callbacks are retried.
- No-answer, provider failure, interrupted call, extraction failure, and callback outage remain visible and recoverable.
- Credentials are not exposed to browsers, logs, API responses, or the repository.

## Decisions needed before implementation

- Location and framework of the career project, and whether this module should be a standalone Laravel/FastAPI service or deployed alongside it.
- Screening questions by job, required languages, and whether questions are fixed or configurable by recruiters.
- Applicant consent text, permitted calling hours/timezone, maximum attempts, and delay after application.
- Whether full transcripts may be retained and for how long; whether answers need human review before use.
