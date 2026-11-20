<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Submodule: answers (ANSWERS-01). Returns structured results to the authenticated career project. */
class CallAnswerController extends Controller
{
    public function show(int $attempt): JsonResponse
    {
        $call = DB::table('career_call_attempts as attempts')
            ->join('career_applications as applications', 'applications.id', '=', 'attempts.career_application_id')
            ->leftJoin('career_answer_extractions as extraction', 'extraction.career_call_attempt_id', '=', 'attempts.id')
            ->where('attempts.id', $attempt)
            ->select('attempts.id', 'attempts.attempt_number', 'attempts.status', 'attempts.provider_status',
                'attempts.termination_reason', 'attempts.dialed_at', 'attempts.answered_at', 'attempts.ended_at',
                'attempts.duration_seconds', 'applications.source', 'applications.application_id',
                'applications.question_set_id', 'applications.question_set_version',
                'extraction.status as extraction_status', 'extraction.error_code as extraction_error')
            ->first();
        abort_unless($call, 404);
        $answers = DB::table('career_call_answers')->where('career_call_attempt_id', $attempt)->orderBy('id')
            ->get(['question_id', 'status', 'answer_text', 'evidence_message_id', 'confidence', 'review_required'])
            ->map(fn ($answer) => [
                'question_id' => $answer->question_id,
                'status' => $answer->status,
                'answer_text' => $answer->answer_text,
                'evidence_message_id' => $answer->evidence_message_id,
                'confidence' => $answer->confidence,
                'review_required' => (bool) $answer->review_required,
            ]);

        return response()->json([
            'attempt_id' => $call->id, 'attempt_number' => $call->attempt_number,
            'source' => $call->source, 'application_id' => $call->application_id,
            'question_set_id' => $call->question_set_id, 'question_set_version' => $call->question_set_version,
            'call_status' => $call->status, 'provider_status' => $call->provider_status,
            'termination_reason' => $call->termination_reason,
            'dialed_at' => $call->dialed_at, 'answered_at' => $call->answered_at,
            'ended_at' => $call->ended_at, 'duration_seconds' => $call->duration_seconds,
            'extraction_status' => $call->extraction_status ?? 'pending',
            'extraction_error' => $call->extraction_error,
            'answers' => $answers,
        ]);
    }
}
