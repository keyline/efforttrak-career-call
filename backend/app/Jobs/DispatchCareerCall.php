<?php

namespace App\Jobs;

use App\Services\CallDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Submodule: call-dispatch (DISPATCH-01). Queue worker entry point for one application. */
class DispatchCareerCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $applicationId) {}

    public function handle(CallDispatchService $dispatch): void
    {
        $dispatch->dispatch($this->applicationId);
    }
}
