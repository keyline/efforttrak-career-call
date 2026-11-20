<?php

namespace Tests\Feature;

use App\Jobs\DeliverCareerResult;
use App\Jobs\ExtractCareerAnswers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Submodule: operations (OPERATIONS-01). Checks access, safe lists, retries, and audit. */
class OperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('operations.api_token', 'operator-secret');
        config()->set('career.api_token', 'career-secret');
    }

    private function failedResult(): array
    {
        $applicationId = DB::table('career_applications')->insertGetId([
            'request_id' => '6e9b8a0c-897b-44e2-a706-740000000001', 'source' => 'career',
            'application_id' => 'app-123', 'job_id' => 'job-45', 'job_title' => 'Engineer',
            'applicant_name' => 'Asha', 'phone' => '+919876543210', 'language' => 'en-IN',
            'question_set_id' => 'engineering', 'question_set_version' => '1', 'consent_granted' => true,
            'submitted_at' => now(), 'state' => 'completed', 'payload_hash' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $callId = DB::table('career_call_attempts')->insertGetId([
            'career_application_id' => $applicationId, 'attempt_number' => 1,
            'status' => 'completed', 'termination_reason' => 'completed_questions',
            'dialed_at' => now()->subMinutes(2), 'ended_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('career_answer_extractions')->insert([
            'career_call_attempt_id' => $callId, 'status' => 'failed', 'run_count' => 3,
            'error_code' => 'invalid_output', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $deliveryId = DB::table('career_result_deliveries')->insertGetId([
            'career_call_attempt_id' => $callId, 'event_id' => '66ba0c9f-6614-4bd7-a384-048000000001',
            'payload_json' => json_encode(['event_id' => '66ba0c9f-6614-4bd7-a384-048000000001']),
            'status' => 'failed_exhausted', 'attempt_count' => 8,
            'last_error_code' => 'http_503', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$callId, $deliveryId];
    }

    public function test_operations_use_a_separate_token_and_omit_phone_and_transcript(): void
    {
        $this->failedResult();
        $this->getJson('/api/v1/operations/calls')->assertUnauthorized();
        $this->withToken('career-secret')->getJson('/api/v1/operations/calls')->assertUnauthorized();
        $response = $this->withToken('operator-secret')->getJson('/api/v1/operations/calls')
            ->assertOk()->assertJsonPath('data.0.extraction_status', 'failed');
        $this->assertStringNotContainsString('+919876543210', $response->getContent());
        $this->assertStringNotContainsString('payload_json', $response->getContent());
        $this->withToken('operator-secret')->getJson('/api/v1/operations/deliveries')
            ->assertOk()->assertJsonPath('data.0.status', 'failed_exhausted');
    }

    public function test_manual_retries_preserve_event_id_and_write_audit_entries(): void
    {
        [$callId, $deliveryId] = $this->failedResult();
        $eventId = DB::table('career_result_deliveries')->where('id', $deliveryId)->value('event_id');

        $this->withToken('operator-secret')->postJson("/api/v1/operations/deliveries/{$deliveryId}/retry")
            ->assertStatus(202)->assertJsonPath('status', 'pending');
        $this->withToken('operator-secret')->postJson("/api/v1/operations/calls/{$callId}/retry-extraction")
            ->assertStatus(202)->assertJsonPath('extraction_status', 'pending');

        Queue::assertPushed(DeliverCareerResult::class, 1);
        Queue::assertPushed(ExtractCareerAnswers::class, 1);
        $this->assertDatabaseHas('career_result_deliveries', [
            'id' => $deliveryId, 'event_id' => $eventId, 'status' => 'pending', 'attempt_count' => 0,
        ]);
        $this->assertDatabaseHas('career_answer_extractions', [
            'career_call_attempt_id' => $callId, 'status' => 'pending', 'run_count' => 0,
        ]);
        $this->assertDatabaseHas('career_operations_audit', ['event_type' => 'delivery_retry_requested', 'actor' => 'operations_api']);
        $this->assertDatabaseHas('career_operations_audit', ['event_type' => 'extraction_retry_requested', 'actor' => 'operations_api']);
        $this->withToken('operator-secret')->getJson('/api/v1/operations/audit')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_invalid_retry_state_does_not_queue_or_audit(): void
    {
        [, $deliveryId] = $this->failedResult();
        DB::table('career_result_deliveries')->where('id', $deliveryId)->update(['status' => 'delivered']);

        $this->withToken('operator-secret')->postJson("/api/v1/operations/deliveries/{$deliveryId}/retry")
            ->assertStatus(409);
        Queue::assertNotPushed(DeliverCareerResult::class);
        $this->assertDatabaseCount('career_operations_audit', 0);
    }

    public function test_question_set_publishing_is_idempotent_and_audited_once(): void
    {
        $payload = ['source' => 'career', 'question_set_id' => 'engineer', 'version' => '1',
            'questions' => [['id' => 'experience', 'text' => 'How many years of experience?']]];

        $this->withToken('operator-secret')->postJson('/api/v1/operations/question-sets', $payload)->assertCreated();
        $this->withToken('operator-secret')->postJson('/api/v1/operations/question-sets', $payload)->assertOk();
        $this->withToken('operator-secret')->getJson('/api/v1/operations/question-sets')
            ->assertOk()->assertJsonPath('data.0.questions.0.id', 'experience');
        $this->assertDatabaseCount('career_operations_audit', 1);
    }
}
