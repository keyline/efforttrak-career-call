<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Submodules: conversation (CONVERSATION-01), answers (ANSWERS-01). Verifies immutable questions and bridge transcript access. */
class ConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('career.api_token', 'career-secret');
        config()->set('call_dispatch.vobiz.bridge_token', 'bridge-secret');
    }

    private function questionSet(): array
    {
        return [
            'source' => 'career', 'question_set_id' => 'engineering', 'version' => '1',
            'questions' => [
                ['id' => 'experience', 'text' => 'How many years of relevant experience do you have?'],
                ['id' => 'notice', 'text' => 'What is your notice period?'],
            ],
        ];
    }

    public function test_question_versions_are_immutable_and_idempotent(): void
    {
        $payload = $this->questionSet();
        $this->withToken('career-secret')->postJson('/api/v1/question-sets', $payload)->assertCreated();
        $this->withToken('career-secret')->postJson('/api/v1/question-sets', $payload)->assertOk();
        $payload['questions'][0]['text'] = 'A changed question';
        $this->withToken('career-secret')->postJson('/api/v1/question-sets', $payload)->assertStatus(409);
        $this->assertDatabaseCount('career_question_sets', 1);
    }

    public function test_question_set_rejects_duplicate_ids_and_missing_token(): void
    {
        $payload = $this->questionSet();
        $this->postJson('/api/v1/question-sets', $payload)->assertUnauthorized();
        $payload['questions'][1]['id'] = 'experience';
        $this->withToken('career-secret')->postJson('/api/v1/question-sets', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['questions.0.id', 'questions.1.id']);
    }

    public function test_unavailable_applicant_can_schedule_a_callback_and_hangup_preserves_it(): void
    {
        $this->withToken('career-secret')->postJson('/api/v1/question-sets', $this->questionSet())->assertCreated();
        $applicationId = DB::table('career_applications')->insertGetId([
            'request_id' => 'e6834504-c0c1-4745-a626-a5f2bf4ef17a', 'source' => 'career', 'application_id' => 'callback-app',
            'job_id' => 'job-8', 'job_title' => 'Engineer', 'applicant_name' => 'Asha', 'phone' => '+919876543210',
            'language' => 'en-IN', 'question_set_id' => 'engineering', 'question_set_version' => '1',
            'consent_granted' => true, 'submitted_at' => now(), 'state' => 'answered',
            'payload_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $attemptId = DB::table('career_call_attempts')->insertGetId([
            'career_application_id' => $applicationId, 'attempt_number' => 1, 'status' => 'answered',
            'provider_call_id' => 'test-call', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = "/api/vobiz/internal/attempts/{$attemptId}/callback";
        $when = now()->addDay()->setTimezone('Asia/Kolkata')->format('Y-m-d\TH:i:sP');
        $this->postJson($url, ['requested_at' => $when])->assertUnauthorized();
        $this->withToken('bridge-secret')->postJson($url, ['requested_at' => $when])->assertOk();
        $this->assertDatabaseHas('career_applications', ['id' => $applicationId, 'state' => 'deferred']);
        $this->assertDatabaseHas('career_call_attempts', ['id' => $attemptId, 'termination_reason' => 'callback_requested']);
        config()->set('call_dispatch.vobiz.callback_token', 'callback-secret');
        $this->postJson("/api/vobiz/hangup?attempt_id={$attemptId}&token=callback-secret", [
            'Event' => 'Hangup', 'CallUUID' => 'test-call', 'CallStatus' => 'completed',
        ])->assertOk();
        $application = DB::table('career_applications')->where('id', $applicationId)->first();
        $this->assertSame('deferred', $application->state);
        $this->assertNotNull($application->next_attempt_at);
    }

    public function test_only_the_bridge_can_read_active_call_context_and_store_turns(): void
    {
        $this->withToken('career-secret')->postJson('/api/v1/question-sets', $this->questionSet())->assertCreated();
        $applicationId = DB::table('career_applications')->insertGetId([
            'request_id' => '6b731571-3c80-4114-8e54-223842f64e1a', 'source' => 'career', 'application_id' => 'app-7',
            'job_id' => 'job-8', 'job_title' => 'Engineer', 'applicant_name' => 'Asha', 'phone' => '+919876543210',
            'language' => 'en-IN', 'question_set_id' => 'engineering', 'question_set_version' => '1',
            'consent_granted' => true, 'submitted_at' => now(), 'state' => 'answered',
            'call_script_json' => json_encode(['introduction' => 'Am I speaking with Asha?',
                'availability_question' => 'Is this a convenient time?',
                'introduction_statement' => 'I am calling about your application.',
                'callback_confirmation' => 'Call on {callback_date} at {callback_time}?']),
            'payload_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $attemptId = DB::table('career_call_attempts')->insertGetId([
            'career_application_id' => $applicationId, 'attempt_number' => 1, 'status' => 'answered',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson("/api/vobiz/internal/attempts/{$attemptId}")->assertUnauthorized();
        $this->withToken('bridge-secret')->getJson("/api/vobiz/internal/attempts/{$attemptId}")
            ->assertOk()->assertJsonPath('questions.1.id', 'notice')->assertJsonPath('job_title', 'Engineer')
            ->assertJsonPath('availability_question', 'Is this a convenient time?')
            ->assertJsonPath('callback_confirmation', 'Call on {callback_date} at {callback_time}?');
        $this->withToken('bridge-secret')->postJson("/api/vobiz/internal/attempts/{$attemptId}/messages", [
            'speaker' => 'applicant', 'message' => 'Two years.',
        ])->assertCreated();
        $this->withToken('wrong-token')->postJson("/api/vobiz/internal/attempts/{$attemptId}/usage", [
            'transcription' => ['model' => 'gpt-4o-transcribe', 'input_tokens' => 100],
        ])->assertUnauthorized();
        $this->withToken('bridge-secret')->postJson("/api/vobiz/internal/attempts/{$attemptId}/usage", [
            'transcription' => ['model' => 'gpt-4o-transcribe', 'input_tokens' => 100, 'output_tokens' => 20],
            'elevenlabs' => ['model' => 'eleven_v4', 'characters' => 350, 'requests' => 3],
        ])->assertOk();
        $this->assertSame(350, json_decode(DB::table('career_call_attempts')->where('id', $attemptId)->value('usage_json'), true)['elevenlabs']['characters']);
        $this->assertDatabaseHas('career_call_messages', ['career_call_attempt_id' => $attemptId, 'speaker' => 'applicant', 'message' => 'Two years.']);
        $this->withToken('bridge-secret')->postJson("/api/vobiz/internal/attempts/{$attemptId}/outcome", [
            'reason' => 'wrong_person',
        ])->assertOk();
        $this->assertDatabaseHas('career_call_attempts', ['id' => $attemptId, 'termination_reason' => 'wrong_person']);
        DB::table('career_call_attempts')->where('id', $attemptId)->update(['status' => 'completed', 'ended_at' => now()]);
        $this->withToken('bridge-secret')->postJson("/api/vobiz/internal/attempts/{$attemptId}/messages", [
            'speaker' => 'applicant', 'message' => 'One final detail.',
        ])->assertCreated();
    }
}
