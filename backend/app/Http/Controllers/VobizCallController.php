<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractCareerAnswers;
use App\Services\OperationsAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Submodules: call-dispatch (DISPATCH-01), answers (ANSWERS-01), operations (OPERATIONS-01). Vobiz callbacks and audit. */
class VobizCallController extends Controller
{
    public function answer(Request $request): Response
    {
        $this->authenticate($request);
        abort_unless($request->input('Event') === 'StartApp', 422);
        $attempt = $this->attempt($request);
        abort_unless($attempt && in_array($attempt->status, ['dialing', 'answered'], true), 404);
        $this->bindProviderId($attempt, (string) $request->input('CallUUID', ''));

        $expires = now()->addMinutes(5)->timestamp;
        $signature = hash_hmac('sha256', $attempt->id.'.'.$expires, config('call_dispatch.vobiz.bridge_token'));
        $stream = $this->withQuery(config('call_dispatch.vobiz.stream_url'), [
            'attempt_id' => $attempt->id, 'expires' => $expires, 'signature' => $signature,
        ]);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Response><Stream bidirectional="true" keepCallAlive="true" contentType="audio/x-l16;rate=16000" streamTimeout="7200">'
            .htmlspecialchars($stream, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</Stream></Response>';
        DB::table('career_call_attempts')->where('id', $attempt->id)->where('status', 'dialing')
            ->update(['status' => 'answered', 'answered_at' => now(), 'updated_at' => now()]);
        DB::table('career_applications')->where('id', $attempt->career_application_id)->where('state', 'dialing')
            ->update(['state' => 'answered', 'updated_at' => now()]);

        return response($xml)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function hangup(Request $request): JsonResponse
    {
        $this->authenticate($request);
        if ($request->input('Event') !== 'Hangup') {
            return response()->json(['ignored' => true]);
        }

        $attempt = $this->attempt($request);
        abort_unless($attempt, 404);
        $providerId = (string) $request->input('CallUUID', '');
        $this->bindProviderId($attempt, $providerId);
        $providerStatus = strtolower((string) $request->input('CallStatus', $request->input('Status', 'failed')));
        $status = match ($providerStatus) {
            'completed' => 'completed',
            'no-answer', 'no_answer', 'timeout' => 'no_answer',
            'busy' => 'busy',
            'canceled', 'cancelled' => 'cancelled',
            default => 'failed',
        };

        $finished = DB::transaction(function () use ($attempt, $status, $providerStatus, $request) {
            $current = DB::table('career_call_attempts')->where('id', $attempt->id)->lockForUpdate()->first();
            if (! $current || in_array($current->status, ['completed', 'no_answer', 'busy', 'failed', 'cancelled'], true)) {
                return false;
            }
            DB::table('career_call_attempts')->where('id', $attempt->id)->update([
                'status' => $status, 'provider_status' => substr($providerStatus, 0, 80),
                'termination_reason' => $current->termination_reason ?? ($status === 'completed' ? 'call_ended' : $status),
                'duration_seconds' => max(0, (int) $request->input('Duration', 0)),
                'ended_at' => now(), 'updated_at' => now(),
            ]);

            $application = DB::table('career_applications')->where('id', $current->career_application_id)->lockForUpdate()->first();
            if (! $application || in_array($application->state, ['cancelled', 'do_not_call'], true)) {
                return true;
            }
            if ($current->termination_reason === 'callback_requested' && $application->state === 'deferred' && $application->next_attempt_at) {
                return true;
            }
            $retry = in_array($status, ['no_answer', 'busy'], true)
                && $current->attempt_number < max(1, (int) config('call_dispatch.max_attempts'));
            DB::table('career_applications')->where('id', $application->id)->update([
                'state' => $status, 'last_call_reason' => $providerStatus,
                'next_attempt_at' => $retry ? now()->addMinutes(max(1, (int) config('call_dispatch.retry_minutes'))) : null,
                'updated_at' => now(),
            ]);

            return true;
        });

        if ($finished) {
            OperationsAudit::system('call_finished', 'call_attempt', $attempt->id, ['status' => $status]);
            // Give the media bridge time to post the final transcribed turn after hangup.
            ExtractCareerAnswers::dispatch($attempt->id)->delay(now()->addSeconds(35));
        }

        return response()->json(['ok' => true]);
    }

    private function authenticate(Request $request): void
    {
        $token = (string) config('call_dispatch.vobiz.callback_token');
        abort_unless($token !== '' && ! str_starts_with($token, 'dummy_')
            && hash_equals($token, (string) $request->query('token', '')), 403);
    }

    private function attempt(Request $request): ?object
    {
        $id = $request->query('attempt_id');
        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        return DB::table('career_call_attempts')->where('id', (int) $id)->first();
    }

    private function bindProviderId(object $attempt, string $providerId): void
    {
        abort_unless($providerId !== '' && (! $attempt->provider_call_id || hash_equals($attempt->provider_call_id, $providerId)), 403);
        DB::table('career_call_attempts')->where('id', $attempt->id)->whereNull('provider_call_id')
            ->update(['provider_call_id' => $providerId, 'updated_at' => now()]);
    }

    private function withQuery(string $url, array $params): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params);
    }
}
