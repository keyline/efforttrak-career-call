<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Submodule: application-intake (INTAKE-01). Verifies intake auth, validation, and idempotency. */
class ApplicationIntakeTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'source' => 'careers-site',
            'application_id' => 'app-123',
            'job_id' => 'job-45',
            'job_title' => 'Software Engineer',
            'applicant_name' => 'Asha Shah',
            'phone' => '+919876543210',
            'language' => 'en-IN',
            'question_set_id' => 'engineering-screen',
            'question_set_version' => '1',
            'consent' => [
                'granted' => true,
                'captured_at' => '2026-11-13T09:00:00+05:30',
                'method' => 'career_form_checkbox',
                'text_version' => '2026-11',
            ],
            'submitted_at' => '2026-11-13T09:00:01+05:30',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('career.api_token', 'test-intake-token');
    }

    public function test_it_requires_the_server_token(): void
    {
        $this->postJson('/api/v1/applications', $this->payload())->assertUnauthorized();
        $this->withToken('wrong')->postJson('/api/v1/applications', $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('career_applications', 0);
    }

    public function test_it_accepts_and_replays_the_same_application(): void
    {
        $first = $this->withToken('test-intake-token')->postJson('/api/v1/applications', $this->payload())
            ->assertCreated()->assertJsonPath('state', 'queued');
        $second = $this->withToken('test-intake-token')->postJson('/api/v1/applications', $this->payload())
            ->assertOk()->assertJsonPath('request_id', $first->json('request_id'));

        $this->assertDatabaseCount('career_applications', 1);
        $this->assertSame('+919876543210', DB::table('career_applications')->value('phone'));
    }

    public function test_it_stores_the_two_step_introduction_and_callback_lines(): void
    {
        $payload = $this->payload();
        $payload['call_script'] = [
            'introduction' => 'Am I speaking with Asha?',
            'availability_question' => 'Is this a convenient time?',
            'introduction_statement' => 'I am calling about your application.',
            'questions' => [['id' => 'q1', 'text' => 'Are you working?']],
            'unavailable' => 'When may I call back?',
            'callback_confirmation' => 'Call on {callback_date} at {callback_time}?',
            'callback_scheduled' => 'Your callback is scheduled.',
            'callback_declined' => 'Thank you for your time. Bye.',
            'conclusion' => 'Thank you. Bye.',
        ];

        $this->withToken('test-intake-token')->postJson('/api/v1/applications', $payload)->assertCreated();
        $stored = json_decode(DB::table('career_applications')->value('call_script_json'), true);
        $this->assertEquals($payload['call_script'], $stored);
    }

    public function test_it_rejects_changed_data_for_an_existing_application(): void
    {
        $this->withToken('test-intake-token')->postJson('/api/v1/applications', $this->payload())->assertCreated();
        $changed = $this->payload();
        $changed['phone'] = '+919876543211';

        $this->withToken('test-intake-token')->postJson('/api/v1/applications', $changed)->assertStatus(409);
        $this->assertDatabaseCount('career_applications', 1);
    }

    public function test_retries_are_stable_when_source_is_defaulted_or_fields_are_reordered(): void
    {
        $payload = $this->payload();
        unset($payload['source']);
        $first = $this->withToken('test-intake-token')->postJson('/api/v1/applications', $payload)->assertCreated();

        $replay = array_reverse($payload, true);
        $replay['source'] = 'career';
        $replay['consent'] = array_reverse($payload['consent'], true);
        $this->withToken('test-intake-token')->postJson('/api/v1/applications', $replay)
            ->assertOk()->assertJsonPath('request_id', $first->json('request_id'));
    }

    public function test_it_rejects_invalid_phone_and_missing_consent_evidence(): void
    {
        $invalid = $this->payload();
        $invalid['phone'] = '9876543210';
        $invalid['consent']['method'] = null;

        $this->withToken('test-intake-token')->postJson('/api/v1/applications', $invalid)
            ->assertUnprocessable()->assertJsonValidationErrors(['phone', 'consent.method']);
    }

    public function test_it_records_missing_consent_without_marking_the_application_accepted(): void
    {
        $payload = $this->payload();
        $payload['consent'] = ['granted' => false];

        $this->withToken('test-intake-token')->postJson('/api/v1/applications', $payload)
            ->assertCreated()->assertJsonPath('state', 'consent_missing');
        $this->assertDatabaseHas('career_applications', ['application_id' => 'app-123', 'consent_granted' => false]);
    }
}
