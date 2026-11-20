<?php

namespace Tests\Feature;

use App\Jobs\DispatchCareerCall;
use App\Services\CallDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Submodules: call-dispatch (DISPATCH-01), conversation (CONVERSATION-01). Verifies dispatch with a published question set. */
class CallDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('career.api_token', 'test-token');
        config()->set('call_dispatch.enabled', true);
        config()->set('call_dispatch.voice_ready', true);
        config()->set('call_dispatch.min_application_age_seconds', 0);
        config()->set('call_dispatch.days', [1, 2, 3, 4, 5, 6, 7]);
        config()->set('call_dispatch.start_hour', 0);
        config()->set('call_dispatch.end_hour', 24);
        config()->set('call_dispatch.vobiz', [
            'auth_id' => 'auth-id', 'auth_token' => 'auth-token', 'caller_id' => '+918000000000',
            'api_base' => 'https://api.vobiz.ai', 'answer_url' => 'https://calls.example.test/api/vobiz/answer',
            'hangup_url' => 'https://calls.example.test/api/vobiz/hangup',
            'stream_url' => 'wss://calls.example.test/api/vobiz/media',
            'callback_token' => 'callback-secret', 'bridge_token' => 'bridge-secret',
        ]);
        DB::table('career_question_sets')->insert([
            'source' => 'career', 'question_set_id' => 'default', 'version' => '1',
            'questions' => json_encode([['id' => 'experience', 'text' => 'How many years of experience do you have?']]),
            'payload_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function application(array $changes = []): int
    {
        $payload = array_replace_recursive([
            'application_id' => 'app-1', 'job_id' => 'job-1', 'job_title' => 'Engineer',
            'applicant_name' => 'Asha', 'phone' => '+919876543210', 'language' => 'en-IN',
            'question_set_id' => 'default', 'question_set_version' => '1',
            'consent' => ['granted' => true, 'captured_at' => now()->subMinutes(5)->toIso8601String(), 'method' => 'form'],
            'submitted_at' => now()->subMinutes(5)->toIso8601String(),
        ], $changes);
        $this->withToken('test-token')->postJson('/api/v1/applications', $payload)->assertCreated();

        return (int) DB::table('career_applications')->where('application_id', $payload['application_id'])->value('id');
    }

    public function test_intake_queues_and_dispatch_dials_once(): void
    {
        $id = $this->application();
        Queue::assertPushed(DispatchCareerCall::class, 1);
        Http::fake(['api.vobiz.ai/*' => Http::response(['request_uuid' => 'vobiz-1'], 200)]);

        app(CallDispatchService::class)->dispatch($id);
        app(CallDispatchService::class)->dispatch($id);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['to'] === '+919876543210'
            && str_contains($request['answer_url'], 'attempt_id=1')
            && $request->hasHeader('X-Auth-ID', 'auth-id'));
        $this->assertDatabaseHas('career_applications', ['id' => $id, 'state' => 'dialing']);
        $this->assertDatabaseHas('career_call_attempts', ['career_application_id' => $id, 'attempt_number' => 1, 'provider_call_id' => 'vobiz-1']);
    }

    public function test_answer_and_hangup_callbacks_update_the_call(): void
    {
        $id = $this->application();
        Http::fake(['api.vobiz.ai/*' => Http::response(['request_uuid' => 'vobiz-2'], 200)]);
        app(CallDispatchService::class)->dispatch($id);
        $query = '?attempt_id=1&token=callback-secret';

        $this->post('/api/vobiz/answer'.$query, ['Event' => 'StartApp', 'CallUUID' => 'vobiz-2'])
            ->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $this->postJson('/api/vobiz/hangup'.$query, ['Event' => 'Hangup', 'CallUUID' => 'vobiz-2', 'CallStatus' => 'completed', 'Duration' => 42])
            ->assertOk();
        $this->assertDatabaseHas('career_applications', ['id' => $id, 'state' => 'completed']);
        $this->assertDatabaseHas('career_call_attempts', ['id' => 1, 'status' => 'completed', 'duration_seconds' => 42]);
    }

    public function test_no_answer_schedules_one_retry_and_rejects_unauthenticated_callback(): void
    {
        $id = $this->application();
        Http::fake(['api.vobiz.ai/*' => Http::response(['request_uuid' => 'vobiz-3'], 200)]);
        app(CallDispatchService::class)->dispatch($id);
        $this->postJson('/api/vobiz/hangup?attempt_id=1', ['Event' => 'Hangup', 'CallUUID' => 'vobiz-3'])->assertForbidden();
        $this->postJson('/api/vobiz/hangup?attempt_id=1&token=callback-secret', [
            'Event' => 'Hangup', 'CallUUID' => 'vobiz-3', 'CallStatus' => 'no-answer',
        ])->assertOk();

        $application = DB::table('career_applications')->where('id', $id)->first();
        $this->assertSame('no_answer', $application->state);
        $this->assertNotNull($application->next_attempt_at);
        app(CallDispatchService::class)->dispatch($id);
        Http::assertSentCount(1);
    }

    public function test_consent_and_do_not_call_prevent_queueing(): void
    {
        $this->application(['application_id' => 'no-consent', 'consent' => ['granted' => false]]);
        $this->application(['application_id' => 'dnc', 'do_not_call' => true]);
        Queue::assertNotPushed(DispatchCareerCall::class);
        $this->assertDatabaseHas('career_applications', ['application_id' => 'dnc', 'state' => 'do_not_call']);
    }

    public function test_outside_call_hours_defers_without_dialing(): void
    {
        $id = $this->application();
        config()->set('call_dispatch.days', []);
        Http::fake();
        app(CallDispatchService::class)->dispatch($id);
        Http::assertNothingSent();
        $this->assertDatabaseHas('career_applications', ['id' => $id, 'state' => 'deferred']);
    }

    public function test_retry_stops_at_the_configured_attempt_limit(): void
    {
        $id = $this->application();
        Http::fakeSequence()->push(['request_uuid' => 'vobiz-first'])->push(['request_uuid' => 'vobiz-second']);
        app(CallDispatchService::class)->dispatch($id);
        $this->postJson('/api/vobiz/hangup?attempt_id=1&token=callback-secret', [
            'Event' => 'Hangup', 'CallUUID' => 'vobiz-first', 'CallStatus' => 'busy',
        ])->assertOk();
        DB::table('career_applications')->where('id', $id)->update(['next_attempt_at' => now()->subMinute()]);

        app(CallDispatchService::class)->dispatch($id);
        $this->postJson('/api/vobiz/hangup?attempt_id=2&token=callback-secret', [
            'Event' => 'Hangup', 'CallUUID' => 'vobiz-second', 'CallStatus' => 'no-answer',
        ])->assertOk();
        app(CallDispatchService::class)->dispatch($id);

        Http::assertSentCount(2);
        $this->assertDatabaseCount('career_call_attempts', 2);
        $this->assertNull(DB::table('career_applications')->where('id', $id)->value('next_attempt_at'));
    }

    public function test_provider_failure_is_recorded_without_automatic_redial(): void
    {
        $id = $this->application();
        Http::fake(['api.vobiz.ai/*' => Http::response(['error' => 'unavailable'], 503)]);

        app(CallDispatchService::class)->dispatch($id);
        app(CallDispatchService::class)->dispatch($id);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('career_applications', ['id' => $id, 'state' => 'failed', 'last_call_reason' => 'dial_request_error']);
        $this->assertDatabaseHas('career_call_attempts', ['career_application_id' => $id, 'status' => 'failed']);
    }
}
