<?php

namespace App\Services;

use App\Jobs\DeliverCareerResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Submodules: result-delivery (DELIVERY-01), operations (OPERATIONS-01). Sends and audits result callbacks. */
class CareerResultDelivery
{
    public function prepare(int $attemptId): void
    {
        $created = DB::transaction(function () use ($attemptId) {
            $run = DB::table('career_answer_extractions')->where('career_call_attempt_id', $attemptId)
                ->where('status', 'completed')->first();
            if (! $run || DB::table('career_result_deliveries')->where('career_call_attempt_id', $attemptId)->exists()) {
                return null;
            }
            $call = DB::table('career_call_attempts as attempts')
                ->join('career_applications as applications', 'applications.id', '=', 'attempts.career_application_id')
                ->where('attempts.id', $attemptId)
                ->select('attempts.*', 'applications.source', 'applications.application_id', 'applications.request_id',
                    'applications.job_id', 'applications.question_set_id', 'applications.question_set_version')
                ->first();
            if (! $call || ! in_array($call->status, ['completed', 'no_answer', 'busy', 'failed', 'cancelled'], true)) {
                return null;
            }
            $eventId = (string) Str::uuid();
            $answers = DB::table('career_call_answers')->where('career_call_attempt_id', $attemptId)->orderBy('id')
                ->get(['question_id', 'status', 'answer_text', 'evidence_message_id', 'confidence', 'review_required'])
                ->map(fn ($answer) => [
                    'question_id' => $answer->question_id, 'status' => $answer->status,
                    'answer_text' => $answer->answer_text, 'evidence_message_id' => $answer->evidence_message_id,
                    'confidence' => $answer->confidence, 'review_required' => (bool) $answer->review_required,
                ])->all();
            $payload = [
                'event_id' => $eventId, 'event_type' => 'career_call.result_ready',
                'occurred_at' => $run->completed_at,
                'source' => $call->source, 'application_id' => $call->application_id,
                'request_id' => $call->request_id, 'job_id' => $call->job_id,
                'question_set' => ['id' => $call->question_set_id, 'version' => $call->question_set_version],
                'call' => [
                    'attempt_id' => $call->id, 'attempt_number' => $call->attempt_number,
                    'status' => $call->status, 'provider_status' => $call->provider_status,
                    'termination_reason' => $call->termination_reason, 'dialed_at' => $call->dialed_at,
                    'answered_at' => $call->answered_at, 'ended_at' => $call->ended_at,
                    'duration_seconds' => $call->duration_seconds,
                ],
                'answers' => $answers,
                'usage' => json_decode($call->usage_json ?: '{}', true),
                'ai_cost_estimate' => CareerCallUsage::estimate(json_decode($call->usage_json ?: '{}', true) ?: []),
                'conversation' => DB::table('career_call_messages')->where('career_call_attempt_id', $attemptId)
                    ->orderBy('id')->get(['id', 'speaker', 'message', 'spoken_at'])
                    ->map(fn ($message) => (array) $message)->all(),
                'transcript_reference' => config('result_delivery.include_transcript_reference')
                    ? url('/api/v1/calls/'.$attemptId.'/transcript') : null,
            ];

            $inserted = DB::table('career_result_deliveries')->insertOrIgnore([
                'career_call_attempt_id' => $attemptId, 'event_id' => $eventId,
                'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'status' => 'pending', 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $inserted ? DB::table('career_result_deliveries')->where('career_call_attempt_id', $attemptId)->value('id') : null;
        });

        if ($created) {
            DeliverCareerResult::dispatch($created);
        }
    }

    public function send(int $deliveryId): void
    {
        $url = (string) config('result_delivery.callback_url');
        $secret = (string) config('result_delivery.callback_secret');
        if (! (str_starts_with($url, 'https://') || str_starts_with($url, 'http://127.0.0.1:')) || $secret === '' || str_starts_with($secret, 'dummy_')) {
            DB::table('career_result_deliveries')->where('id', $deliveryId)
                ->whereIn('status', ['pending', 'retryable', 'configuration_blocked'])
                ->update(['status' => 'configuration_blocked', 'last_error_code' => 'callback_configuration', 'updated_at' => now()]);

            return;
        }

        $delivery = DB::transaction(function () use ($deliveryId) {
            $row = DB::table('career_result_deliveries')->where('id', $deliveryId)->lockForUpdate()->first();
            if (! $row || in_array($row->status, ['delivered', 'failed_permanent', 'failed_exhausted'], true)
                || ($row->status === 'sending' && $row->updated_at && now()->diffInSeconds($row->updated_at) < 120)
                || ($row->next_attempt_at && now()->lessThan($row->next_attempt_at))) {
                return null;
            }
            if ($row->attempt_count >= max(1, (int) config('result_delivery.max_attempts'))) {
                DB::table('career_result_deliveries')->where('id', $deliveryId)->update([
                    'status' => 'failed_exhausted', 'last_error_code' => 'attempt_limit', 'updated_at' => now(),
                ]);

                return null;
            }
            DB::table('career_result_deliveries')->where('id', $deliveryId)->update([
                'status' => 'sending', 'attempt_count' => $row->attempt_count + 1,
                'next_attempt_at' => null, 'updated_at' => now(),
            ]);

            return $row;
        });
        if (! $delivery) {
            return;
        }

        $timestamp = (string) now()->timestamp;
        $body = (string) $delivery->payload_json;
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        try {
            $response = Http::withHeaders([
                'X-Career-Event-ID' => $delivery->event_id,
                'X-Career-Timestamp' => $timestamp,
                'X-Career-Signature' => $signature,
            ])->withBody($body, 'application/json')->withoutRedirecting()->timeout(15)->post($url);
            $status = $response->status();
            if ($response->successful()) {
                DB::table('career_result_deliveries')->where('id', $deliveryId)->update([
                    'status' => 'delivered', 'delivered_at' => now(), 'last_http_status' => $status,
                    'last_error_code' => null, 'next_attempt_at' => null, 'updated_at' => now(),
                ]);
                OperationsAudit::system('delivery_succeeded', 'delivery', $deliveryId, [
                    'http_status' => $status, 'attempt' => $delivery->attempt_count + 1,
                ]);

                return;
            }
            $retryable = $status === 408 || $status === 429 || $status >= 500;
            $this->failed($deliveryId, $delivery->attempt_count + 1, $retryable, $status, 'http_'.$status);
        } catch (\Throwable $exception) {
            Log::warning('Career result callback failed', ['delivery_id' => $deliveryId, 'exception' => get_class($exception)]);
            $this->failed($deliveryId, $delivery->attempt_count + 1, true, null, 'transport_error');
        }
    }

    private function failed(int $deliveryId, int $attemptCount, bool $retryable, ?int $httpStatus, string $code): void
    {
        $exhausted = $attemptCount >= max(1, (int) config('result_delivery.max_attempts'));
        $status = ! $retryable ? 'failed_permanent' : ($exhausted ? 'failed_exhausted' : 'retryable');
        $seconds = min(21600, 30 * (2 ** min(10, $attemptCount - 1)));
        DB::table('career_result_deliveries')->where('id', $deliveryId)->update([
            'status' => $status, 'last_http_status' => $httpStatus, 'last_error_code' => $code,
            'next_attempt_at' => $status === 'retryable' ? now()->addSeconds($seconds) : null,
            'updated_at' => now(),
        ]);
        OperationsAudit::system('delivery_failed', 'delivery', $deliveryId, [
            'status' => $status, 'http_status' => $httpStatus, 'error_code' => $code, 'attempt' => $attemptCount,
        ]);
    }
}
