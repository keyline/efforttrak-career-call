<?php

namespace Tests\Feature;

use App\Jobs\ExtractCareerAnswers;
use App\Services\CareerAnswerExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Submodule: answers (ANSWERS-01). Verifies cited extraction, empty calls, failures, and result access. */
class AnswersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('answers.api_key', 'test-openai-key');
        config()->set('answers.model', 'gpt-4o-mini');
        config()->set('career.api_token', 'career-token');
        config()->set('call_dispatch.vobiz.callback_token', 'callback-token');
    }

    private function completedCall(): int
    {
        DB::table('career_question_sets')->insert([
            'source' => 'career', 'question_set_id' => 'screen', 'version' => '1',
            'questions' => json_encode([
                ['id' => 'experience', 'text' => 'How much experience?'],
                ['id' => 'notice', 'text' => 'What is your notice period?'],
            ]),
            'payload_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $applicationId = DB::table('career_applications')->insertGetId([
            'request_id' => '1d58fba2-38bb-4dfb-b445-f3db81292330', 'source' => 'career', 'application_id' => 'app-1',
            'job_id' => 'job-1', 'job_title' => 'Engineer', 'applicant_name' => 'Asha', 'phone' => '+919876543210',
            'language' => 'en-IN', 'question_set_id' => 'screen', 'question_set_version' => '1',
            'consent_granted' => true, 'submitted_at' => now(), 'state' => 'completed',
            'payload_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('career_call_attempts')->insertGetId([
            'career_application_id' => $applicationId, 'attempt_number' => 1, 'status' => 'completed',
            'provider_call_id' => 'vobiz-1', 'provider_status' => 'completed',
            'termination_reason' => 'completed_questions', 'dialed_at' => now()->subMinutes(3),
            'answered_at' => now()->subMinutes(2), 'ended_at' => now(), 'duration_seconds' => 120,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function message(int $attemptId, string $speaker, string $text): int
    {
        return DB::table('career_call_messages')->insertGetId([
            'career_call_attempt_id' => $attemptId, 'speaker' => $speaker, 'message' => $text,
            'spoken_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function openAiResponse(array $answers): array
    {
        return ['status' => 'completed', 'output' => [[
            'type' => 'message', 'content' => [[
                'type' => 'output_text', 'text' => json_encode(['answers' => $answers]),
            ]],
        ]]];
    }

    public function test_it_saves_exact_cited_answers_and_unanswered_question(): void
    {
        $attemptId = $this->completedCall();
        $this->message($attemptId, 'ai', 'How much experience?');
        $evidenceId = $this->message($attemptId, 'applicant', 'I have five years of experience.');
        Http::fake(['api.openai.com/*' => Http::response($this->openAiResponse([
            ['question_id' => 'experience', 'status' => 'answered', 'evidence_message_id' => $evidenceId,
                'evidence_text' => 'five years of experience', 'confidence' => 'high', 'review_required' => false],
            ['question_id' => 'notice', 'status' => 'unanswered', 'evidence_message_id' => null,
                'evidence_text' => null, 'confidence' => null, 'review_required' => false],
        ]), 200)]);

        app(CareerAnswerExtractor::class)->extract($attemptId);
        app(CareerAnswerExtractor::class)->extract($attemptId);

        Http::assertSentCount(1);
        $usage = json_decode(DB::table('career_call_attempts')->where('id', $attemptId)->value('usage_json'), true);
        $this->assertSame('gpt-4o-mini', $usage['extraction']['model']);
        $this->assertDatabaseHas('career_call_answers', ['question_id' => 'experience', 'answer_text' => 'five years of experience', 'evidence_message_id' => $evidenceId]);
        $this->assertDatabaseHas('career_call_answers', ['question_id' => 'notice', 'status' => 'unanswered', 'answer_text' => null]);
        $this->withToken('career-token')->getJson("/api/v1/calls/{$attemptId}/answers")
            ->assertOk()->assertJsonPath('termination_reason', 'completed_questions')
            ->assertJsonPath('duration_seconds', 120)->assertJsonPath('answers.0.status', 'answered');
    }

    public function test_it_marks_every_question_unanswered_without_applicant_speech_or_api_call(): void
    {
        $attemptId = $this->completedCall();
        $this->message($attemptId, 'ai', 'Hello?');
        Http::fake();

        app(CareerAnswerExtractor::class)->extract($attemptId);

        Http::assertNothingSent();
        $this->assertDatabaseCount('career_call_answers', 2);
        $this->assertDatabaseHas('career_answer_extractions', ['career_call_attempt_id' => $attemptId, 'status' => 'completed']);
    }

    public function test_it_rejects_an_invented_quote_without_saving_partial_answers(): void
    {
        $attemptId = $this->completedCall();
        $evidenceId = $this->message($attemptId, 'applicant', 'I am available next month.');
        Http::fake(['api.openai.com/*' => Http::response($this->openAiResponse([
            ['question_id' => 'experience', 'status' => 'answered', 'evidence_message_id' => $evidenceId,
                'evidence_text' => 'ten years', 'confidence' => 'high', 'review_required' => false],
            ['question_id' => 'notice', 'status' => 'unanswered', 'evidence_message_id' => null,
                'evidence_text' => null, 'confidence' => null, 'review_required' => false],
        ]), 200)]);

        app(CareerAnswerExtractor::class)->extract($attemptId);

        $this->assertDatabaseCount('career_call_answers', 0);
        $this->assertDatabaseHas('career_answer_extractions', ['career_call_attempt_id' => $attemptId, 'status' => 'failed', 'error_code' => 'invalid_output']);
    }

    public function test_a_declined_question_keeps_the_applicants_words_and_requires_review_when_confidence_is_low(): void
    {
        $attemptId = $this->completedCall();
        $evidenceId = $this->message($attemptId, 'applicant', 'I would rather not answer that question.');
        Http::fake(['api.openai.com/*' => Http::response($this->openAiResponse([
            ['question_id' => 'experience', 'status' => 'declined', 'evidence_message_id' => $evidenceId,
                'evidence_text' => 'rather not answer', 'confidence' => 'low', 'review_required' => false],
            ['question_id' => 'notice', 'status' => 'unanswered', 'evidence_message_id' => null,
                'evidence_text' => null, 'confidence' => null, 'review_required' => false],
        ]), 200)]);

        app(CareerAnswerExtractor::class)->extract($attemptId);

        $this->withToken('career-token')->getJson("/api/v1/calls/{$attemptId}/answers")
            ->assertOk()->assertJsonPath('answers.0.status', 'declined')
            ->assertJsonPath('answers.0.answer_text', 'rather not answer')
            ->assertJsonPath('answers.0.review_required', true);
    }

    public function test_hangup_queues_extraction_only_on_first_terminal_transition(): void
    {
        $attemptId = $this->completedCall();
        DB::table('career_call_attempts')->where('id', $attemptId)->update(['status' => 'answered', 'ended_at' => null]);
        Queue::fake();
        $url = "/api/vobiz/hangup?attempt_id={$attemptId}&token=callback-token";
        $body = ['Event' => 'Hangup', 'CallUUID' => 'vobiz-1', 'CallStatus' => 'completed'];

        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk();

        Queue::assertPushed(ExtractCareerAnswers::class, 1);
    }

    public function test_result_endpoint_requires_career_token(): void
    {
        $attemptId = $this->completedCall();
        $this->getJson("/api/v1/calls/{$attemptId}/answers")->assertUnauthorized();
    }
}
