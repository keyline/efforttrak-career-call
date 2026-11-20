<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Submodules: call-dispatch (DISPATCH-01), conversation (CONVERSATION-01). Claims calls only with a published question version. */
class CallDispatchService
{
    public function dispatch(int $applicationId): void
    {
        if (! config('call_dispatch.enabled') || ! config('call_dispatch.voice_ready')) {
            return;
        }

        $config = config('call_dispatch.vobiz');
        if (! $this->configured($config)) {
            DB::table('career_applications')->where('id', $applicationId)
                ->whereIn('state', ['accepted', 'queued', 'deferred', 'no_answer', 'busy', 'question_set_missing'])
                ->update(['state' => 'configuration_blocked', 'last_call_reason' => 'vobiz_configuration', 'updated_at' => now()]);

            return;
        }

        // Serialize claims so the global hourly limit also holds across queue workers.
        $claim = Cache::lock('career-call-dispatch-claim', 20)->block(10, fn () => DB::transaction(function () use ($applicationId) {
            $application = DB::table('career_applications')->where('id', $applicationId)->lockForUpdate()->first();
            if (! $application || ! in_array($application->state, ['accepted', 'queued', 'deferred', 'no_answer', 'busy', 'configuration_blocked', 'question_set_missing'], true)) {
                return null;
            }

            $priorApplication = DB::table('career_applications')
                ->where('source', $application->source)
                ->where('job_id', $application->job_id)
                ->where('phone', $application->phone)
                ->where('id', '<', $application->id)
                ->where('created_at', '>=', now()->subMonths(3))
                ->exists();
            if ($priorApplication) {
                DB::table('career_applications')->where('id', $applicationId)->update([
                    'state' => 'duplicate', 'next_attempt_at' => null,
                    'last_call_reason' => 'duplicate_application', 'updated_at' => now(),
                ]);
                return null;
            }

            if (! $application->consent_granted || $application->do_not_call) {
                DB::table('career_applications')->where('id', $applicationId)->update([
                    'state' => $application->do_not_call ? 'do_not_call' : 'consent_missing',
                    'next_attempt_at' => null, 'updated_at' => now(),
                ]);

                return null;
            }

            $questionSetExists = DB::table('career_question_sets')
                ->where('source', $application->source)
                ->where('question_set_id', $application->question_set_id)
                ->where('version', $application->question_set_version)->exists();
            if (! $questionSetExists) {
                DB::table('career_applications')->where('id', $applicationId)->update([
                    'state' => 'question_set_missing', 'last_call_reason' => 'question_set_missing', 'updated_at' => now(),
                ]);

                return null;
            }

            $attempts = DB::table('career_call_attempts')->where('career_application_id', $applicationId)->count();
            if ($attempts >= max(1, (int) config('call_dispatch.max_attempts'))) {
                DB::table('career_applications')->where('id', $applicationId)->update(['state' => 'failed', 'next_attempt_at' => null, 'updated_at' => now()]);

                return null;
            }

            $now = CarbonImmutable::now(config('call_dispatch.timezone'));
            $due = $this->nextEligibleTime($application, $now);
            if ($due->greaterThan($now)) {
                DB::table('career_applications')->where('id', $applicationId)->update([
                    'state' => 'deferred', 'next_attempt_at' => $due->utc(), 'last_call_reason' => 'call_policy', 'updated_at' => now(),
                ]);

                return null;
            }

            $attemptId = DB::table('career_call_attempts')->insertGetId([
                'career_application_id' => $applicationId, 'attempt_number' => $attempts + 1,
                'status' => 'dialing', 'dialed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('career_applications')->where('id', $applicationId)->update([
                'state' => 'dialing', 'next_attempt_at' => null, 'last_call_reason' => null, 'updated_at' => now(),
            ]);

            return ['attempt_id' => $attemptId, 'phone' => $application->phone];
        }));

        if (! $claim) {
            return;
        }

        try {
            $response = Http::withHeaders(['X-Auth-ID' => $config['auth_id'], 'X-Auth-Token' => $config['auth_token']])
                ->timeout(15)->post(rtrim($config['api_base'], '/').'/api/v1/Account/'.rawurlencode($config['auth_id']).'/Call/', [
                    'from' => $config['caller_id'], 'to' => $claim['phone'],
                    'answer_url' => $this->withQuery($config['answer_url'], ['attempt_id' => $claim['attempt_id'], 'token' => $config['callback_token']]),
                    'answer_method' => 'POST',
                    'hangup_url' => $this->withQuery($config['hangup_url'], ['attempt_id' => $claim['attempt_id'], 'token' => $config['callback_token']]),
                    'hangup_method' => 'POST',
                    'time_limit' => max(30, min(1800, (int) config('call_dispatch.time_limit_seconds'))),
                ]);
            $providerId = $response->json('request_uuid') ?? $response->json('call_uuid');
            if (! $response->successful() || ! is_string($providerId) || $providerId === '') {
                Log::warning('Vobiz dial rejected', [
                    'attempt_id' => $claim['attempt_id'],
                    'http_status' => $response->status(),
                    'provider_error' => preg_replace('/\+?\d{8,15}/', '<number>', (string) $response->json('error', $response->json('message', ''))),
                ]);
                throw new \RuntimeException('Vobiz did not accept the call.');
            }

            DB::table('career_call_attempts')->where('id', $claim['attempt_id'])->whereNull('provider_call_id')
                ->update(['provider_call_id' => $providerId, 'updated_at' => now()]);
        } catch (\Throwable $exception) {
            // An ambiguous provider error must not be retried automatically; the provider may have dialed.
            Log::warning('Vobiz dial request failed', ['attempt_id' => $claim['attempt_id'], 'exception' => get_class($exception)]);
            DB::transaction(function () use ($applicationId, $claim) {
                $attempt = DB::table('career_call_attempts')->where('id', $claim['attempt_id'])->lockForUpdate()->first();
                if (! $attempt || $attempt->status !== 'dialing' || $attempt->provider_call_id) {
                    return;
                }
                DB::table('career_call_attempts')->where('id', $attempt->id)->update(['status' => 'failed', 'provider_status' => 'dial_request_error', 'ended_at' => now(), 'updated_at' => now()]);
                DB::table('career_applications')->where('id', $applicationId)->update(['state' => 'failed', 'last_call_reason' => 'dial_request_error', 'updated_at' => now()]);
            });
        }
    }

    private function nextEligibleTime(object $application, CarbonImmutable $now): CarbonImmutable
    {
        $due = $now;
        foreach ([$application->next_attempt_at, $application->submitted_at] as $timestamp) {
            if ($timestamp) {
                $candidate = CarbonImmutable::parse($timestamp)->setTimezone($now->timezone);
                if ($timestamp === $application->submitted_at) {
                    $candidate = $candidate->addSeconds(max(0, (int) config('call_dispatch.min_application_age_seconds')));
                }
                if ($candidate->greaterThan($due)) {
                    $due = $candidate;
                }
            }
        }

        $lastPhoneCall = DB::table('career_call_attempts')->join('career_applications', 'career_applications.id', '=', 'career_call_attempts.career_application_id')
            ->where('career_applications.phone', $application->phone)->where('career_applications.id', '!=', $application->id)
            ->whereNotNull('career_call_attempts.dialed_at')->max('career_call_attempts.dialed_at');
        if ($lastPhoneCall) {
            $cooldown = CarbonImmutable::parse($lastPhoneCall)->setTimezone($now->timezone)->addHours(max(0, (int) config('call_dispatch.phone_cooldown_hours')));
            if ($cooldown->greaterThan($due)) {
                $due = $cooldown;
            }
        }

        $hourlyCalls = DB::table('career_call_attempts')->where('dialed_at', '>=', $now->subHour()->utc())->count();
        if ($hourlyCalls >= max(1, (int) config('call_dispatch.max_calls_per_hour'))) {
            $oldest = DB::table('career_call_attempts')->where('dialed_at', '>=', $now->subHour()->utc())->orderBy('dialed_at')->value('dialed_at');
            $hourDue = CarbonImmutable::parse($oldest)->setTimezone($now->timezone)->addHour()->addSecond();
            if ($hourDue->greaterThan($due)) {
                $due = $hourDue;
            }
        }

        $days = config('call_dispatch.days');
        $start = max(0, min(23, (int) config('call_dispatch.start_hour')));
        $end = max($start + 1, min(24, (int) config('call_dispatch.end_hour')));
        for ($i = 0; $i < 8; $i++) {
            if (in_array($due->dayOfWeekIso, $days, true) && $due->hour >= $start && $due->hour < $end) {
                return $due;
            }
            $due = $due->hour < $start && in_array($due->dayOfWeekIso, $days, true)
                ? $due->setTime($start, 0)
                : $due->addDay()->setTime($start, 0);
        }

        return $due;
    }

    private function configured(array $config): bool
    {
        foreach (['auth_id', 'auth_token', 'caller_id', 'answer_url', 'hangup_url', 'stream_url', 'callback_token', 'bridge_token'] as $key) {
            if (empty($config[$key]) || str_starts_with((string) $config[$key], 'dummy_')) {
                return false;
            }
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $config['caller_id'])
            && str_starts_with($config['answer_url'], 'https://')
            && str_starts_with($config['hangup_url'], 'https://')
            && str_starts_with($config['stream_url'], 'wss://');
    }

    private function withQuery(string $url, array $params): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($params);
    }
}
