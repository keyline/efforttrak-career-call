<?php

namespace Tests\Feature;

use App\Jobs\DeliverCareerResult;
use App\Services\CareerResultDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Submodule: result-delivery (DELIVERY-01). Verifies event snapshots, signatures, retries, and reconciliation. */
class ResultDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('career.api_token', 'career-token');
        config()->set('result_delivery.callback_url', 'https://career.example.test/api/call-results');
        config()->set('result_delivery.callback_secret', 'callback-secret');
    }

    private function completedResult(): int
    {
        $applicationId = DB::table('career_applications')->insertGetId([
            'request_id' => '6e9b8a0c-897b-44e2-a706-740000000001', 'source' => 'career',
            'application_id' => 'app-123', 'job_id' => 'job-45', 'job_title' => 'Engineer',
            'applicant_name' => 'Asha', 'phone' => '+919876543210', 'language' => 'en-IN',
            'question_set_id' => 'engineering', 'question_set_version' => '1', 'consent_granted' => true,
            'submitted_at' => now(), 'state' => 'completed', 'payload_hash' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $attemptId = DB::table('career_call_attempts')->insertGetId([
            'career_application_id' => $applicationId, 'attempt_number' => 1,
            'status' => 'completed', 'provider_status' => 'completed',
            'termination_reason' => 'completed_questions', 'dialed_at' => now()->subMinutes(3),
            'answered_at' => now()->subMinutes(2), 'ended_at' => now(), 'duration_seconds' => 120,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('career_answer_extractions')->insert([
            'career_call_attempt_id' => $attemptId, 'status' => 'completed',
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('career_call_answers')->insert([
            'career_call_attempt_id' => $attemptId, 'question_id' => 'experience',
            'status' => 'answered', 'answer_text' => 'five years', 'confidence' => 'high',
            'review_required' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $attemptId;
    }

    public function test_it_sends_a_signed_stable_event_and_marks_delivery(): void
    {
        $attemptId = $this->completedResult();
        DB::table('career_call_attempts')->where('id', $attemptId)->update(['usage_json' => json_encode([
            'transcription' => ['model' => 'gpt-4o-transcribe', 'input_tokens' => 100],
        ])]);
        app(CareerResultDelivery::class)->prepare($attemptId);
        app(CareerResultDelivery::class)->prepare($attemptId);
        Queue::assertPushed(DeliverCareerResult::class, 1);
        $delivery = DB::table('career_result_deliveries')->first();
        Http::fake(['career.example.test/*' => Http::response(null, 204)]);

        app(CareerResultDelivery::class)->send($delivery->id);
        app(CareerResultDelivery::class)->send($delivery->id);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($delivery) {
            $timestamp = $request->header('X-Career-Timestamp')[0] ?? '';
            $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'callback-secret');
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://career.example.test/api/call-results'
                && ($request->header('X-Career-Event-ID')[0] ?? '') === $delivery->event_id
                && ($request->header('X-Career-Signature')[0] ?? '') === $signature
                && $body['application_id'] === 'app-123'
                && $body['request_id'] === '6e9b8a0c-897b-44e2-a706-740000000001'
                && $body['question_set']['version'] === '1'
                && $body['answers'][0]['answer_text'] === 'five years'
                && $body['usage']['transcription']['input_tokens'] === 100;
        });
        $this->assertDatabaseHas('career_result_deliveries', ['id' => $delivery->id, 'status' => 'delivered', 'attempt_count' => 1]);
    }

    public function test_transient_failure_uses_backoff_and_reuses_the_same_event(): void
    {
        $attemptId = $this->completedResult();
        app(CareerResultDelivery::class)->prepare($attemptId);
        $delivery = DB::table('career_result_deliveries')->first();
        Http::fakeSequence()->push(['error' => 'temporary'], 503)->push(null, 204);

        app(CareerResultDelivery::class)->send($delivery->id);
        $retry = DB::table('career_result_deliveries')->where('id', $delivery->id)->first();
        $this->assertSame('retryable', $retry->status);
        $this->assertNotNull($retry->next_attempt_at);
        app(CareerResultDelivery::class)->send($delivery->id);
        Http::assertSentCount(1);
        DB::table('career_result_deliveries')->where('id', $delivery->id)->update(['next_attempt_at' => now()->subSecond()]);

        app(CareerResultDelivery::class)->send($delivery->id);

        Http::assertSentCount(2);
        $this->assertDatabaseHas('career_result_deliveries', ['id' => $delivery->id, 'event_id' => $delivery->event_id, 'status' => 'delivered', 'attempt_count' => 2]);
    }

    public function test_permanent_failure_and_missing_configuration_do_not_loop(): void
    {
        $attemptId = $this->completedResult();
        app(CareerResultDelivery::class)->prepare($attemptId);
        $delivery = DB::table('career_result_deliveries')->first();
        config()->set('result_delivery.callback_secret', '');
        Http::fake(['career.example.test/*' => Http::response(['message' => 'bad request'], 400)]);
        app(CareerResultDelivery::class)->send($delivery->id);
        Http::assertNothingSent();
        $this->assertDatabaseHas('career_result_deliveries', ['id' => $delivery->id, 'status' => 'configuration_blocked', 'attempt_count' => 0]);

        config()->set('result_delivery.callback_secret', 'callback-secret');
        app(CareerResultDelivery::class)->send($delivery->id);
        $this->assertDatabaseHas('career_result_deliveries', ['id' => $delivery->id, 'status' => 'failed_permanent', 'attempt_count' => 1]);
    }

    public function test_reconciliation_lists_calls_and_optional_transcript(): void
    {
        $attemptId = $this->completedResult();
        config()->set('result_delivery.include_transcript_reference', true);
        app(CareerResultDelivery::class)->prepare($attemptId);
        DB::table('career_call_messages')->insert([
            'career_call_attempt_id' => $attemptId, 'speaker' => 'applicant', 'message' => 'five years',
            'spoken_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/applications/app-123/calls')->assertUnauthorized();
        $this->withToken('career-token')->getJson('/api/v1/applications/app-123/calls')
            ->assertOk()->assertJsonPath('calls.0.call_status', 'completed')
            ->assertJsonPath('calls.0.answers.0.question_id', 'experience');
        $this->withToken('career-token')->getJson("/api/v1/calls/{$attemptId}/transcript")
            ->assertOk()->assertJsonPath('messages.0.message', 'five years');
        $payload = json_decode(DB::table('career_result_deliveries')->value('payload_json'), true);
        $this->assertStringContainsString("/api/v1/calls/{$attemptId}/transcript", $payload['transcript_reference']);
    }

    public function test_scan_recovers_a_completed_extraction_without_an_outbox_row(): void
    {
        $attemptId = $this->completedResult();

        Artisan::call('career-results:scan');

        $this->assertDatabaseHas('career_result_deliveries', ['career_call_attempt_id' => $attemptId, 'status' => 'pending']);
        Queue::assertPushed(DeliverCareerResult::class);
    }
}
