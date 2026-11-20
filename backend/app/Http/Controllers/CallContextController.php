<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\CareerCallUsage;

/** Submodules: conversation (CONVERSATION-01), answers (ANSWERS-01). Shares context and records turns and call outcome. */
class CallContextController extends Controller
{
    public function show(Request $request, int $attempt): JsonResponse
    {
        $this->authenticate($request);
        $record = DB::table('career_call_attempts as attempts')
            ->join('career_applications as applications', 'applications.id', '=', 'attempts.career_application_id')
            ->join('career_question_sets as sets', function ($join) {
                $join->on('sets.source', '=', 'applications.source')
                    ->on('sets.question_set_id', '=', 'applications.question_set_id')
                    ->on('sets.version', '=', 'applications.question_set_version');
            })
            ->where('attempts.id', $attempt)->whereIn('attempts.status', ['dialing', 'answered'])
            ->select('attempts.id', 'attempts.answered_at', 'attempts.provider_call_id', 'applications.applicant_name', 'applications.job_title',
                'applications.language', 'applications.question_set_id', 'applications.question_set_version', 'applications.call_script_json', 'sets.questions')
            ->first();
        abort_unless($record, 404);

        return response()->json([
            'attempt_id' => $record->id,
            'provider_call_id' => $record->provider_call_id,
            'applicant_name' => $record->applicant_name,
            'job_title' => $record->job_title,
            'language' => $record->language,
            'question_set_id' => $record->question_set_id,
            'question_set_version' => $record->question_set_version,
            'questions' => (json_decode($record->call_script_json ?: '{}', true)['questions'] ?? json_decode($record->questions, true)),
            'introduction' => json_decode($record->call_script_json ?: '{}', true)['introduction'] ?? null,
            'availability_question' => json_decode($record->call_script_json ?: '{}', true)['availability_question'] ?? null,
            'introduction_statement' => json_decode($record->call_script_json ?: '{}', true)['introduction_statement'] ?? null,
            'conclusion' => json_decode($record->call_script_json ?: '{}', true)['conclusion'] ?? null,
            'unavailable' => json_decode($record->call_script_json ?: '{}', true)['unavailable'] ?? null,
            'callback_confirmation' => json_decode($record->call_script_json ?: '{}', true)['callback_confirmation'] ?? null,
            'callback_scheduled' => json_decode($record->call_script_json ?: '{}', true)['callback_scheduled'] ?? null,
            'callback_declined' => json_decode($record->call_script_json ?: '{}', true)['callback_declined'] ?? null,
            'max_duration_seconds' => max(30, min(1800, (int) config('call_dispatch.time_limit_seconds'))),
        ]);
    }

    public function message(Request $request, int $attempt): JsonResponse
    {
        $this->authenticate($request);
        $data = $request->validate([
            'speaker' => ['required', 'in:ai,applicant'],
            'message' => ['required', 'string', 'max:10000'],
        ]);
        $call = DB::table('career_call_attempts')->where('id', $attempt)->first(['status', 'ended_at']);
        abort_unless($call && (in_array($call->status, ['dialing', 'answered'], true)
            || ($call->ended_at && now()->diffInSeconds($call->ended_at) <= 30)), 404);
        DB::table('career_call_messages')->insert([
            'career_call_attempt_id' => $attempt, ...$data, 'spoken_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    public function outcome(Request $request, int $attempt): JsonResponse
    {
        $this->authenticate($request);
        $data = $request->validate([
            'reason' => ['required', 'in:unavailable,declined_call,wrong_person,completed_questions,incomplete,time_limit'],
        ]);
        $updated = DB::table('career_call_attempts')->where('id', $attempt)
            ->whereIn('status', ['dialing', 'answered'])->whereNull('termination_reason')
            ->update(['termination_reason' => $data['reason'], 'updated_at' => now()]);
        abort_unless($updated || DB::table('career_call_attempts')->where('id', $attempt)->exists(), 404);

        return response()->json(['ok' => true]);
    }

    public function usage(Request $request, int $attempt): JsonResponse
    {
        $this->authenticate($request);
        $data = $request->validate([
            'transcription' => ['sometimes', 'array'],
            'transcription.model' => ['required_with:transcription', 'string', 'max:80'],
            'transcription.input_tokens' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'transcription.output_tokens' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'transcription.audio_input_tokens' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'transcription.seconds' => ['sometimes', 'numeric', 'min:0', 'max:1800'],
            'callback' => ['sometimes', 'array'],
            'callback.model' => ['required_with:callback', 'string', 'max:80'],
            'callback.input_tokens' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'callback.output_tokens' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'elevenlabs' => ['sometimes', 'array'],
            'elevenlabs.model' => ['required_with:elevenlabs', 'string', 'max:80'],
            'elevenlabs.characters' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'elevenlabs.requests' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]);
        abort_unless(DB::table('career_call_attempts')->where('id', $attempt)->exists(), 404);
        CareerCallUsage::merge($attempt, $data);

        return response()->json(['ok' => true]);
    }

    public function callback(Request $request, int $attempt): JsonResponse
    {
        $this->authenticate($request);
        $data = $request->validate([
            'requested_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
        ]);
        $when = \Carbon\CarbonImmutable::parse($data['requested_at'])->utc();
        abort_unless($when->greaterThan(now()->addMinute()) && $when->lessThan(now()->addMonths(3)), 422);
        $saved = DB::transaction(function () use ($attempt, $when) {
            $call = DB::table('career_call_attempts')->where('id', $attempt)->lockForUpdate()->first();
            abort_unless($call && in_array($call->status, ['dialing', 'answered'], true), 404);
            abort_unless($call->attempt_number < max(1, (int) config('call_dispatch.max_attempts')), 409);
            $application = DB::table('career_applications')->where('id', $call->career_application_id)->lockForUpdate()->first();
            abort_unless($application && ! $application->do_not_call && $application->consent_granted, 409);
            DB::table('career_call_attempts')->where('id', $attempt)->update([
                'termination_reason' => 'callback_requested', 'updated_at' => now(),
            ]);
            DB::table('career_applications')->where('id', $application->id)->update([
                'state' => 'deferred', 'next_attempt_at' => $when,
                'last_call_reason' => 'callback_requested', 'updated_at' => now(),
            ]);
            return $when->toIso8601String();
        });
        return response()->json(['scheduled_at' => $saved]);
    }

    private function authenticate(Request $request): void
    {
        $token = (string) config('call_dispatch.vobiz.bridge_token');
        abort_unless($token !== '' && $request->bearerToken() && hash_equals($token, $request->bearerToken()), 401);
    }
}
