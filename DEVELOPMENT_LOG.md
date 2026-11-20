# Development log

This is the durable record of implemented submodules. Use a stable ID in this log and in comments at the top of each submodule's code files. Add a new entry whenever a submodule is developed or changed. Each entry records purpose, behavior, files, database changes, verification, and remaining limits. Do not record credentials or applicant data here. Framework generated files are listed by directory when unchanged.

## ENV-01 — Development environment and service scaffolds

**Date:** 2026-11-13  
**Status:** Complete for local development  
**Purpose:** Provide installable Laravel and Python services, local configuration templates, and a basic startup check before building calling features.

### Files worked on

| File or path | Reason |
| --- | --- |
| `backend/` Laravel scaffold, including `composer.json` and `composer.lock` | Created the PHP API project and locked its dependencies. Upgraded framework to Laravel 12 after the Laravel 11 install showed security advisories. Unchanged framework generated files belong to this scaffold. |
| `backend/.env.example` | Documented local API and planned Vobiz integration settings without storing secrets. |
| `ai-service/requirements.txt` | Declared FastAPI, Uvicorn, HTTP, WebSocket, and environment loading dependencies. |
| `ai-service/.env.example` | Documented voice service configuration keys without live credentials. |
| `ai-service/app/__init__.py` | Established the Python application package. |
| `ai-service/app/main.py` | Added the ASGI application and `/health` startup endpoint. |
| `ai-service/README.md` | Documented virtual environment setup and the Uvicorn command. |
| `.gitignore` | Excluded secrets, installed dependencies, local SQLite data, logs, and temporary files. |
| `README.md` | Documented local setup and the distinction between the working health check and planned call flow. |
| `PLAN.md` | Recorded the architecture and selected technologies. |

### Database tables

| Table | Change | Reason |
| --- | --- | --- |
| Laravel default `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Created by framework starter migrations in the local SQLite database. | Provide the framework baseline and database-backed queue support. No career data is stored in these tables by ENV-01. |

### Verification and limits

- Laravel starter tests passed. Python dependencies imported and `pip check` found no broken requirements. `/health` returned HTTP 200 in a local test client.
- The ASGI service has no Vobiz or OpenAI calling routes yet. Live credentials were not added to tracked files.

## INTAKE-01 — Application intake API

**Date:** 2026-11-13  
**Status:** Implemented  
**Purpose:** Receive application events from the career project, validate and authenticate them, and store each application once for later call dispatch.

### Behavior and contract

- `POST /api/v1/applications` accepts source (default `career`), application and job identifiers, job title, applicant name, E.164 phone, language, question-set ID/version, consent evidence, and submission time.
- A configured `CAREER_API_TOKEN` is required as a Bearer token. Requests are rate limited to 60 per minute.
- New requests return HTTP 201 with `request_id` and `state`. Identical retries return HTTP 200 with the original values. Changed data for an existing `(source, application_id)` returns HTTP 409.
- Granted consent originally produced `accepted`; DISPATCH-01 now produces `queued` and enqueues a dispatch job. Missing consent produces `consent_missing`. Intake itself does not place a call.
- Invalid input returns HTTP 422; missing or incorrect credentials return HTTP 401.

### Files worked on

| File | Reason |
| --- | --- |
| `backend/bootstrap/app.php` | Registered API routing under `/api`. |
| `backend/routes/api.php` | Registered the protected, rate-limited versioned intake endpoint. |
| `backend/config/career.php` | Loaded the intake Bearer token from server environment. |
| `backend/app/Http/Middleware/AuthenticateCareerApi.php` | Rejected unauthenticated server-to-server requests. |
| `backend/app/Http/Controllers/ApplicationIntakeController.php` | Validated payloads, assigned stable request IDs, stored consent state, and handled duplicate requests. |
| `backend/database/migrations/2026_11_13_000000_create_career_applications_table.php` | Defined durable intake storage and uniqueness constraints. |
| `backend/tests/Feature/ApplicationIntakeTest.php` | Covered authentication, validation, initial receipt, idempotent retries, conflicts, and missing consent. |
| `backend/.env.example` | Added `CAREER_API_TOKEN` as a configuration placeholder. |
| `README.md` | Documented the request example, response codes, and local setup. |

### Database tables

| Table | Change | Reason |
| --- | --- | --- |
| `career_applications` | Created. | Holds the career project's application reference and data needed for later call dispatch without writing into the career project's database. |

`career_applications` stores a generated unique `request_id`, `source`, `application_id`, job details, applicant name and phone, language, question-set ID/version, consent fields, `submitted_at`, `state`, a canonical payload hash, and timestamps. The unique `(source, application_id)` constraint prevents duplicate records even under concurrent requests. The hash distinguishes an identical retry from conflicting application data. The `state` index prepares for later dispatch scans.

### Verification and limits

- PHPUnit: 8 tests, 24 assertions passed. The route was listed at `POST api/v1/applications`; the migration ran against local SQLite.
- The API only records intake. Queue dispatch, question-set lookup, calls, and result delivery are separate planned submodules.

## LOG-01 — Submodule documentation convention

**Date:** 2026-11-13  
**Status:** Active convention  
**Purpose:** Keep file ownership and schema changes traceable during future development.

### Files worked on

| File or path | Reason |
| --- | --- |
| `DEVELOPMENT_LOG.md` | Created this structured record and the entry template below. |
| `backend/bootstrap/app.php`, `backend/routes/api.php`, `backend/config/career.php`, `backend/app/Http/Middleware/AuthenticateCareerApi.php`, `backend/app/Http/Controllers/ApplicationIntakeController.php`, `backend/database/migrations/2026_11_13_000000_create_career_applications_table.php`, `backend/tests/Feature/ApplicationIntakeTest.php` | Added `INTAKE-01` comments identifying the owning submodule. |
| `ai-service/app/__init__.py`, `ai-service/app/main.py` | Added `ENV-01` module comments. |

### Database tables

No tables created or modified. LOG-01 changes documentation and comments only.

### Future entry template

For each new submodule, add an entry with: **ID and name**, **date**, **status**, **purpose**, **behavior/API contract**, a **files worked on** table with one reason per file, a **database tables** table listing every created or modified table and why (or explicitly saying none), **verification**, and **remaining limits**. Add the same ID in a short top-level comment or docstring in each new or edited code file. When a file serves several submodules, list each applicable ID in its header comment.

## DISPATCH-01 — Call dispatch and Vobiz attempt tracking

**Date:** 2026-11-13  
**Status:** Implemented behind disabled live-call gates  
**Purpose:** Queue eligible applications, apply outbound calling policy, place Vobiz calls, record attempt states, and schedule bounded retries.

### Behavior and contract

- A newly eligible application enters `queued` and enqueues `DispatchCareerCall`. A scheduled `career-calls:scan` command finds due queued (and legacy accepted), deferred, busy, no-answer, and configuration-blocked applications every minute.
- Dispatch requires both `CALL_DISPATCH_ENABLED=true` and `CAREER_VOICE_READY=true`, plus valid server-side Vobiz credentials and public HTTPS/WSS URLs. Both flags default to false because the media conversation bridge has not yet been developed.
- Eligibility checks consent, the intake `do_not_call` flag, minimum application age, configured local days and hours, a per-phone cooldown across applications, a global hourly call limit, and maximum attempts. Calls outside policy are deferred with `next_attempt_at`. A cache lock serializes attempt claims across workers for the hourly limit.
- Each dial creates one `career_call_attempts` row. Vobiz receives an answer URL and a hangup URL containing an attempt ID and callback token. The answer callback returns bidirectional Stream XML with a short-lived HMAC-signed media URL. The hangup callback maps provider results to `completed`, `no_answer`, `busy`, `failed`, or `cancelled`.
- Busy and no-answer attempts receive a configurable delayed retry until the attempt limit. A failed or ambiguous dial request is terminal for automatic retries because Vobiz may have accepted a call despite the local request error.
- Callback authentication uses `VOBIZ_CALLBACK_TOKEN`, separate from the career API token. The current code does not yet offer a remote hangup action or a way to update the do-not-call flag after intake.

### Files worked on

| File | Reason |
| --- | --- |
| `backend/app/Http/Controllers/ApplicationIntakeController.php` | Accepts an optional do-not-call flag and queues accepted applications. Tagged `INTAKE-01` and `DISPATCH-01`. |
| `backend/app/Jobs/DispatchCareerCall.php` | Runs dispatch through the Laravel queue. |
| `backend/app/Services/CallDispatchService.php` | Applies call policy, claims a unique attempt, sends the Vobiz request, and records provider errors. |
| `backend/app/Http/Controllers/VobizCallController.php` | Authenticates Vobiz callbacks, returns Stream XML, and applies terminal call status and retry dates. |
| `backend/routes/api.php` | Registers answer and hangup callback routes. Tagged `INTAKE-01` and `DISPATCH-01`. |
| `backend/routes/console.php` | Adds the due-call scan command and minute schedule. |
| `backend/config/call_dispatch.php` | Defines environment-backed call policy and Vobiz settings. |
| `backend/database/migrations/2026_11_13_000001_create_call_dispatch_tables.php` | Adds eligibility fields and durable attempt history. |
| `backend/tests/Feature/CallDispatchTest.php` | Verifies queueing, dialing, callbacks, retries, limits, consent, call windows, and provider failure with mocked Vobiz responses. |
| `backend/.env.example` | Documents disabled live-call gates, Vobiz settings, and call policy options. |
| `README.md` | Documents workers, scheduler, callback URLs, configuration, and current limits. |
| `DEVELOPMENT_LOG.md` | Records this submodule's files and schema changes. |

### Database tables

| Table | Change | Reason |
| --- | --- | --- |
| `career_applications` | Added `do_not_call`, `next_attempt_at`, and `last_call_reason`; dispatch updates `state`. | Prevent prohibited calls, schedule policy deferrals/retries, and expose the latest dispatch reason. |
| `career_call_attempts` | Created with a foreign key to `career_applications`, unique attempt number per application, unique Vobiz call ID, status, provider status, and call timestamps/duration. | Preserve every call attempt and make callback updates and retry limits auditable. |
| Laravel default `jobs` | Used by the database queue; no schema change. | Persist background dispatch jobs until a worker handles them. |
| Laravel default `cache_locks` | Used by the default database cache driver; no schema change. | Serialize dispatch claims across workers while enforcing the hourly call limit. |

### Verification and limits

- PHPUnit: 15 tests, 60 assertions passed after this module. Vobiz HTTP requests were mocked; no real call was placed.
- A public WSS media bridge and AI recruitment conversation are still required before setting `CAREER_VOICE_READY=true`. Answer capture and result delivery are separate planned submodules.
- A call already underway cannot currently be remotely hung up when the external career project's do-not-call state changes. Intake checks the submitted state; a later update API is future work.

## CONVERSATION-01 — Versioned recruitment conversation

**Date:** 2026-11-13  
**Status:** Implemented and locally tested; live audio pilot pending  
**Purpose:** Give each call an immutable screening question version and connect the applicant's Vobiz audio to an AI recruitment conversation.

### Behavior and contract

- The career project publishes 1–10 ordered questions via authenticated `POST /api/v1/question-sets` using `source`, `question_set_id`, and `version`. Each question has a distinct stable ID and text. Repeating identical content returns HTTP 200; changing a published version returns HTTP 409.
- The dispatcher requires the exact question version named by the application. If it is absent, the application enters `question_set_missing` and the scheduled scan retries after publication without dialing first.
- The Vobiz answer callback's HMAC-signed media URL connects to FastAPI at `/api/vobiz/media`. The bridge verifies the signature and expiry, then loads applicant/job/question context from Laravel with `VOBIZ_BRIDGE_TOKEN`.
- The OpenAI Realtime instructions identify the AI assistant and job and confirm availability. A Python `ScreeningFlow` reveals only one question at a time; the model must call `begin_screening` and then `advance_question` with the current question ID and answered/declined result to receive the next one. `end_screening` handles unavailability or refusal of the whole call. The bridge relays 16 kHz Vobiz PCM to 24 kHz OpenAI PCM and returns generated audio to Vobiz.
- Applicant and AI transcribed turns are posted to a bridge-only Laravel endpoint. The Vobiz request and bridge both bound call duration with `CALL_TIME_LIMIT_SECONDS`.

### Files worked on

| File | Reason |
| --- | --- |
| `backend/database/migrations/2026_11_13_000002_create_conversation_tables.php` | Created immutable question versions and transcript storage. |
| `backend/app/Http/Controllers/QuestionSetController.php` | Validates, publishes, and protects question-set versions from mutation. |
| `backend/app/Http/Controllers/CallContextController.php` | Serves active call context and saves conversation turns only to the trusted voice bridge. |
| `backend/app/Services/CallDispatchService.php` | Blocks dialing when the named question version is missing. Tagged `DISPATCH-01` and `CONVERSATION-01`. |
| `backend/routes/console.php` | Scans `question_set_missing` applications after publication. Tagged `DISPATCH-01` and `CONVERSATION-01`. |
| `backend/routes/api.php` | Registers question-set and bridge-only context/message routes. Tagged `INTAKE-01`, `DISPATCH-01`, and `CONVERSATION-01`. |
| `backend/tests/Feature/CallDispatchTest.php` | Publishes a question fixture so dispatch tests exercise the new eligibility check. Tagged `DISPATCH-01` and `CONVERSATION-01`. |
| `backend/tests/Feature/ConversationTest.php` | Tests immutable versions, validation, bridge authentication, context, and turn storage. |
| `ai-service/app/main.py` | Registers the Vobiz media WebSocket route. Tagged `ENV-01` and `CONVERSATION-01`. |
| `ai-service/app/voice_bridge.py` | Verifies signed streams, builds the recruitment prompt, and relays audio and transcripts between Vobiz, OpenAI, and Laravel. |
| `ai-service/tests/test_conversation.py` | Tests conversation instructions, stream HMAC expiry, and PCM resampling. |
| `ai-service/.env.example` | Adds model and voice configuration placeholders. |
| `ai-service/README.md`, `README.md` | Document local startup, question publication, deployment settings, and live-test limits. |
| `DEVELOPMENT_LOG.md` | Records this submodule's files and schema changes. |

### Database tables

| Table | Change | Reason |
| --- | --- | --- |
| `career_question_sets` | Created with a unique `(source, question_set_id, version)` key, ordered JSON questions, and content hash. | Preserve the exact questions used for each application and prevent edits to a published version. |
| `career_call_messages` | Created with an attempt foreign key, speaker, message, and spoken timestamp. | Retain the call's transcribed turns for audit and later structured answer extraction. |
| `career_applications` | No schema change; dispatch also writes the `question_set_missing` state and reason. | Stop calls that refer to an unpublished question version and resume them after publication. |

### Verification and limits

- PHPUnit: 18 tests, 76 assertions passed; Python unittest: 5 tests passed. These tests mock or avoid live provider traffic.
- Live Vobiz and OpenAI audio, voice quality, transcript accuracy, language behavior, and interruption handling require a consented pilot. Keep both live-call gates disabled until then.
- The service enforces question order when tool calls arrive, while AI speech and tool-use behavior still need a live pilot. Structured answers were added later in `ANSWERS-01`; result delivery is a subsequent submodule.

## ANSWERS-01 — Transcript-backed structured answers

**Date:** 2026-11-13  
**Status:** Implemented and locally tested; live extraction quality review pending  
**Purpose:** Turn completed call transcripts into one evidence-backed result for each published screening question and expose the call outcome to the career project.

### Behavior and contract

- The Vobiz hangup callback records final call status, provider status, duration, end time, and a termination reason. The voice bridge can record `unavailable`, `declined_call`, `completed_questions`, or `time_limit`; a provider-only completion falls back to `call_ended`.
- The voice bridge may post final transcript turns for 30 seconds after hangup. An extraction job is delayed by 35 seconds so the final-turn window closes first. A scheduled scan finds missing and retryable failed extractions.
- The extractor loads the exact published question version and ordered transcript. With applicant speech, it calls the OpenAI Responses API using strict JSON Schema. With no applicant speech, it saves every question as `unanswered` without an AI call.
- Every `answered` or `declined` result must cite one existing applicant message ID and an exact nonempty substring of that message. The saved `answer_text` is that verbatim quote. Each answer also has `confidence` and `review_required`; low confidence forces review. `unanswered` has no quote or evidence.
- The service validates the full set of question IDs and all evidence before one transaction replaces the answer rows. Invalid or invented output fails the run without partial answers. Runs are idempotent per call attempt and keep a safe error code when they fail.
- Authenticated `GET /api/v1/calls/{attempt}/answers` returns the source/application reference, question-set version, call status, provider status, termination reason, timestamps, duration, extraction status, and ordered answers. Webhook delivery was added later in `DELIVERY-01`.

### Files worked on

| File | Reason |
| --- | --- |
| `backend/database/migrations/2026_11_13_000003_create_answer_tables.php` | Adds call termination reason, extraction run tracking, and evidence-backed answer storage. |
| `backend/config/answers.php` | Reads the server-side OpenAI key, extraction model, and run limit. |
| `backend/app/Jobs/ExtractCareerAnswers.php` | Runs extraction asynchronously after a terminal call. |
| `backend/app/Services/CareerAnswerExtractor.php` | Requests structured model output, validates exact applicant evidence, stores all answers atomically, and records safe failure codes. |
| `backend/app/Http/Controllers/CallAnswerController.php` | Exposes protected structured results and call metadata to the career project. |
| `backend/app/Http/Controllers/VobizCallController.php` | Preserves termination reason and queues extraction once after terminal status. Tagged `DISPATCH-01` and `ANSWERS-01`. |
| `backend/app/Http/Controllers/CallContextController.php` | Allows late transcript turns and records a voice-service outcome. Tagged `CONVERSATION-01` and `ANSWERS-01`. |
| `backend/routes/api.php` | Adds the result and bridge outcome endpoints. Tagged `INTAKE-01`, `DISPATCH-01`, `CONVERSATION-01`, and `ANSWERS-01`. |
| `backend/routes/console.php` | Schedules recovery of missing or failed extraction jobs. Tagged `DISPATCH-01`, `CONVERSATION-01`, and `ANSWERS-01`. |
| `backend/tests/Feature/AnswersTest.php` | Tests exact evidence, unanswered results, invented-quote rejection, callback idempotency, and access control. |
| `backend/tests/Feature/ConversationTest.php` | Tests the post-hangup final-turn grace period. Tagged `CONVERSATION-01` and `ANSWERS-01`. |
| `ai-service/app/voice_bridge.py` | Reports semantic conversation termination reasons to Laravel. Tagged `CONVERSATION-01` and `ANSWERS-01`. |
| `backend/.env.example` | Documents extraction configuration and the shared OpenAI credential. |
| `README.md`, `ai-service/README.md` | Document extraction behavior, configuration, result endpoint, and live limits. |
| `DEVELOPMENT_LOG.md` | Records the files, schema changes, and verification for this module. |

### Database tables

| Table | Change | Reason |
| --- | --- | --- |
| `career_call_attempts` | Added nullable `termination_reason`; existing status and timing fields are populated on hangup. | Preserve why a conversation stopped separately from the provider's technical status. |
| `career_answer_extractions` | Created, one row per call attempt with status, model, run count, error code, and run timestamps. | Track idempotent extraction, retries, and failures without losing the call. |
| `career_call_answers` | Created, unique by `(career_call_attempt_id, question_id)` with answer state, exact quote, applicant message reference, confidence, and review flag. | Store one reviewable result per published question and prevent duplicate answers. |
| `career_call_messages` | No schema change; accepts final bridge turns shortly after hangup. | Include speech that arrives after the provider callback in extraction. |

### Verification and limits

- PHPUnit: 24 tests, 97 assertions passed after this module. OpenAI requests were mocked; the invalid-quote test saved no answer rows.
- Extraction quality and transcript accuracy require a consented live pilot. Model output is checked against transcript text, but semantic correctness still needs human review where indicated.
- A missing API key or repeated invalid output records a failed extraction. The scheduled scan retries up to `ANSWER_EXTRACTION_MAX_RUNS`; an operator must inspect failures after that. Callback sending was added in `DELIVERY-01`; receiver storage in the main project is still pending.

## DELIVERY-01 — Signed result callback and reconciliation

**Date:** 2026-11-13  
**Status:** Sender implemented and locally tested; receiver integration pending  
**Purpose:** Send each completed extraction to the career project with a stable event ID, signed JSON, bounded retries, and a way to reconcile after an outage.

### Behavior and contract

- Once an extraction completes, the service creates one immutable result payload and outbox event per call attempt. The event contains `source`, `application_id`, `request_id`, `job_id`, event ID, question-set ID/version, call status/times/termination reason, and ordered answers. A protected transcript reference is included only when enabled.
- The queue worker posts the exact stored JSON to a configured HTTPS `CAREER_CALLBACK_URL`. Headers carry event ID, Unix timestamp, and HMAC-SHA256 of `timestamp + "." + raw body` using `CAREER_CALLBACK_SECRET`. The timestamp/signature change per try; the event ID and body do not. Redirects are not followed.
- HTTP 2xx marks delivery complete. HTTP 408/429/5xx and transport errors retry with exponential backoff until `CAREER_DELIVERY_MAX_ATTEMPTS`; other 4xx responses are permanent failures. Missing callback configuration leaves the event `configuration_blocked` without using an attempt.
- A scheduled `career-results:scan` command repairs missing outbox events and queues due, configuration-blocked, or stale sending events. The same event ID allows the receiver to upsert idempotently even after a timeout where the sender did not see the acknowledgement.
- Protected `GET /api/v1/applications/{application_id}/calls?source=career` returns attempt, extraction, answer, event, and delivery state for reconciliation. Protected `GET /api/v1/calls/{attempt_id}/transcript` serves the optional transcript reference.
- The receiver must verify the raw-body signature and timestamp, match the existing application, and transactionally upsert by event ID. Its implementation belongs in the career project, which has not been provided in this workspace. The contract is in `docs/CALLBACK_CONTRACT.md`.

### Files worked on

| File | Reason |
| --- | --- |
| `backend/database/migrations/2026_11_13_000004_create_result_deliveries.php` | Adds a durable per-attempt outbox, stable event UUID, payload snapshot, status, retry schedule, and delivery audit fields. |
| `backend/config/result_delivery.php` | Reads callback destination, secret, retry cap, and optional transcript-reference switch. |
| `backend/app/Jobs/DeliverCareerResult.php` | Sends one outbox event on the Laravel queue. |
| `backend/app/Services/CareerResultDelivery.php` | Builds stable payloads, signs HTTP requests, handles delivery outcomes and retry backoff. |
| `backend/app/Services/CareerAnswerExtractor.php` | Prepares the outbox after an atomic successful answer extraction. Tagged `ANSWERS-01` and `DELIVERY-01`. |
| `backend/app/Http/Controllers/ResultReconciliationController.php` | Exposes application call history and protected transcript access. |
| `backend/routes/api.php` | Registers reconciliation and transcript routes. Tagged `INTAKE-01`, `DISPATCH-01`, `CONVERSATION-01`, `ANSWERS-01`, and `DELIVERY-01`. |
| `backend/routes/console.php` | Adds a scheduled outbox repair and retry scan. Tagged `DISPATCH-01`, `CONVERSATION-01`, `ANSWERS-01`, and `DELIVERY-01`. |
| `backend/tests/Feature/ResultDeliveryTest.php` | Tests stable signed events, success, transient backoff, permanent and configuration failure, reconciliation, optional transcript, and outbox recovery. |
| `backend/.env.example` | Documents delivery retry and transcript-reference configuration. |
| `docs/CALLBACK_CONTRACT.md` | Defines the receiver's payload, signature verification, idempotent transaction, acknowledgements, and reconciliation. |
| `README.md` | Documents result delivery setup and the receiver integration dependency. |
| `DEVELOPMENT_LOG.md` | Records this submodule's files and schema changes. |

### Database tables

| Table | Change | Reason |
| --- | --- | --- |
| `career_result_deliveries` | Created with unique call attempt and event ID, JSON payload, status, try count, next try, last HTTP/error, delivered time, and timestamps. | Preserve an immutable result for safe retries and make delivery failures visible. |
| `career_answer_extractions` | No schema change; completed rows trigger outbox creation and are scanned for missing events. | Ensure every successfully extracted result eventually gets an event. |
| `career_call_answers` | No schema change; read to snapshot ordered answers into the event. | Send exactly the reviewed result that extraction saved. |
| `career_call_messages` | No schema change; read by the protected transcript endpoint if enabled. | Let the career project retrieve the source conversation without embedding it in every webhook. |
| Laravel default `jobs` | Used by the queue; no schema change. | Persist outbound delivery jobs and retries. |

### Verification and limits

- PHPUnit: 29 tests, 118 assertions passed after this module. HTTP delivery was mocked; no callback was sent to a real career project.
- The career project's callback URL, codebase, answer tables, and receiver implementation were not available. Do not configure live delivery until the receiver has signature, event-ID uniqueness, transaction, and response behavior from the contract.
- A consented end-to-end call, live OpenAI extraction, and real callback acknowledgement remain necessary before enabling automatic calling.

## OPERATIONS-01 — Protected operations and audit API

**Date:** 2026-11-16  
**Status:** Implemented and locally verified  
**Purpose:** Give operators a separate, limited API to inspect call and delivery state, publish question versions, retry failed work, and trace changes without exposing applicant speech or phone numbers.

### Behavior and contract

- A separate `OPERATIONS_API_TOKEN` protects all `/api/v1/operations/*` routes. Requests are rate limited to 30 per minute.
- Paginated call and delivery lists select operational fields only. They do not select phone numbers, transcript turns, answer text, or stored callback payloads.
- Manual delivery retries retain the same event ID and payload. Failed answer extractions can also be requeued. Ineligible states return `409`. The queue worker performs the actual work.
- Question-set publication uses the existing immutable, idempotent contract. New versions and manual retries are recorded in the audit table; terminal calls, extraction outcomes, and delivery outcomes also add safe system events.
- Tokens and provider credentials remain in environment configuration. Audit and application logs contain resource IDs, statuses, counts, and safe error codes rather than applicant content.

### Files worked on

| File | Reason |
| --- | --- |
| `backend/database/migrations/2026_11_16_000000_create_operations_audit.php` | Creates the durable operations event ledger. |
| `backend/config/operations.php` | Reads the separate operations token from environment configuration. |
| `backend/app/Http/Middleware/AuthenticateOperations.php` | Rejects missing or invalid operations credentials. |
| `backend/app/Http/Controllers/OperationsController.php` | Implements safe status lists, question-set publication, manual retries, and audit listing. |
| `backend/app/Services/OperationsAudit.php` | Writes structured audit events without applicant content. |
| `backend/app/Services/CareerResultDelivery.php` | Audits delivery success and failure. Tagged `DELIVERY-01` and `OPERATIONS-01`. |
| `backend/app/Services/CareerAnswerExtractor.php` | Audits extraction success and failure. Tagged `ANSWERS-01`, `DELIVERY-01`, and `OPERATIONS-01`. |
| `backend/app/Http/Controllers/VobizCallController.php` | Audits terminal call outcomes. Tagged `DISPATCH-01`, `ANSWERS-01`, and `OPERATIONS-01`. |
| `backend/routes/api.php` | Registers the protected, rate-limited operations routes. Tagged `OPERATIONS-01` alongside earlier modules. |
| `backend/.env.example` | Documents the operations token without storing a value. |
| `backend/tests/Feature/OperationsTest.php` | Verifies access control, redaction, retries, audit, and idempotent publication. |
| `README.md` | Documents operator setup and endpoints. |
| `DEVELOPMENT_LOG.md` | Records the submodule contract, files, schema, and verification. |

### Database tables

| Table | Change | Reason |
| --- | --- | --- |
| `career_operations_audit` | Created with event type, resource type/ID, actor, safe JSON details, and timestamp. | Trace operator actions and system outcomes without applicant content. |
| `career_result_deliveries` | No schema change; failed rows can be reset to pending with their event ID and payload retained. | Retry delivery without creating a duplicate result event. |
| `career_answer_extractions` | No schema change; failed rows can be reset to pending. | Allow manual recovery after extraction failures. |
| `career_call_attempts`, `career_call_answers`, `career_question_sets`, `career_applications` | No schema change; read for status and question-set configuration. | Show operational state while omitting personal content from lists. |

### Verification and limits

- PHPUnit operations feature tests: 4 tests, 28 assertions passed. Full backend suite: 33 tests, 146 assertions passed.
- Pint passed on all changed PHP files. The repository-wide check still reports pre-existing formatting in three Laravel scaffold files.
- Browser-use checked the running local API: unauthenticated calls returned `401`; authenticated call, delivery, and audit lists returned `200`; publishing a test question set returned `201`, it appeared in the filtered list, and an audit event was recorded. The local database contains that `browser-test` question-set version as a test fixture.
- The career project receiver and a live provider call remain outside this local module test.
