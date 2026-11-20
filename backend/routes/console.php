<?php

use App\Jobs\DeliverCareerResult;
use App\Jobs\DispatchCareerCall;
use App\Jobs\ExtractCareerAnswers;
use App\Services\CareerResultDelivery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Submodules: call-dispatch (DISPATCH-01), conversation (CONVERSATION-01), answers (ANSWERS-01), result-delivery (DELIVERY-01).
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Submodules: call-dispatch (DISPATCH-01), conversation (CONVERSATION-01). Recover due applications and missing question versions.
Artisan::command('career-calls:scan', function () {
    if (! config('call_dispatch.enabled') || ! config('call_dispatch.voice_ready')) {
        return;
    }
    DB::table('career_applications')->whereIn('state', ['accepted', 'queued', 'deferred', 'no_answer', 'busy', 'configuration_blocked', 'question_set_missing'])
        ->where(function ($query) {
            $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
        })->orderBy('id')->limit(100)->pluck('id')->each(fn ($id) => DispatchCareerCall::dispatch((int) $id));
})->purpose('Queue eligible career call attempts');

Schedule::command('career-calls:scan')->everyMinute()->withoutOverlapping();

// Submodule: answers (ANSWERS-01). Recover missing or transiently failed extraction jobs.
Artisan::command('career-answers:scan', function () {
    DB::table('career_call_attempts as attempts')
        ->leftJoin('career_answer_extractions as runs', 'runs.career_call_attempt_id', '=', 'attempts.id')
        ->whereIn('attempts.status', ['completed', 'no_answer', 'busy', 'failed', 'cancelled'])
        ->where('attempts.ended_at', '<=', now()->subSeconds(30))
        ->where(function ($query) {
            $query->whereNull('runs.id')
                ->orWhere(function ($retry) {
                    $retry->where('runs.status', 'failed')
                        ->where('runs.run_count', '<', max(1, (int) config('answers.max_runs')))
                        ->where('runs.updated_at', '<=', now()->subMinutes(5));
                });
        })
        ->orderBy('attempts.id')->limit(100)->pluck('attempts.id')
        ->each(fn ($id) => ExtractCareerAnswers::dispatch((int) $id));
})->purpose('Queue missing or retryable career answer extractions');

Schedule::command('career-answers:scan')->everyFiveMinutes()->withoutOverlapping();

// Submodule: result-delivery (DELIVERY-01). Repair missing outbox rows and retry due callbacks.
Artisan::command('career-results:scan', function () {
    DB::table('career_answer_extractions as extraction')
        ->leftJoin('career_result_deliveries as delivery', 'delivery.career_call_attempt_id', '=', 'extraction.career_call_attempt_id')
        ->where('extraction.status', 'completed')->whereNull('delivery.id')
        ->orderBy('extraction.id')->limit(100)->pluck('extraction.career_call_attempt_id')
        ->each(fn ($attemptId) => app(CareerResultDelivery::class)->prepare((int) $attemptId));

    DB::table('career_result_deliveries')
        ->where(function ($query) {
            $query->where(function ($due) {
                $due->whereIn('status', ['pending', 'retryable'])
                    ->where('next_attempt_at', '<=', now());
            })->orWhere(function ($blocked) {
                $blocked->where('status', 'configuration_blocked');
            })->orWhere(function ($stale) {
                $stale->where('status', 'sending')->where('updated_at', '<=', now()->subMinutes(2));
            });
        })->orderBy('id')->limit(100)->pluck('id')
        ->each(fn ($id) => DeliverCareerResult::dispatch((int) $id));
})->purpose('Recover and retry career result callbacks');

Schedule::command('career-results:scan')->everyMinute()->withoutOverlapping();
