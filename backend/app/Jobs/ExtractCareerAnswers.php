<?php

namespace App\Jobs;

use App\Services\CareerAnswerExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Submodule: answers (ANSWERS-01). Runs structured extraction after a terminal call. */
class ExtractCareerAnswers implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $attemptId) {}

    public function handle(CareerAnswerExtractor $extractor): void
    {
        $extractor->extract($this->attemptId);
    }
}
