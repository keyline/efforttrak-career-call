<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Submodule: result-delivery (DELIVERY-01). Protected application call history and optional transcript reference. */
class ResultReconciliationController extends Controller
{
    public function calls(Request $request, string $applicationId): JsonResponse
    {
        $source = $request->validate([
            'source' => ['sometimes', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ])['source'] ?? 'career';
        $application = DB::table('career_applications')->where('source', $source)
            ->where('application_id', $applicationId)->first();
        abort_unless($application, 404);
        $calls = DB::table('career_call_attempts as attempts')
            ->leftJoin('career_answer_extractions as extraction', 'extraction.career_call_attempt_id', '=', 'attempts.id')
            ->leftJoin('career_result_deliveries as delivery', 'delivery.career_call_attempt_id', '=', 'attempts.id')
            ->where('attempts.career_application_id', $application->id)->orderBy('attempts.attempt_number')
            ->select('attempts.id', 'attempts.attempt_number', 'attempts.status', 'attempts.provider_status',
                'attempts.termination_reason', 'attempts.dialed_at', 'attempts.answered_at', 'attempts.ended_at',
                'attempts.duration_seconds', 'extraction.status as extraction_status',
                'delivery.event_id', 'delivery.status as delivery_status', 'delivery.attempt_count as delivery_attempts',
                'delivery.last_error_code as delivery_error', 'delivery.delivered_at')
            ->get()->map(function ($call) {
                $answers = DB::table('career_call_answers')->where('career_call_attempt_id', $call->id)
                    ->orderBy('id')->get(['question_id', 'status', 'answer_text', 'evidence_message_id', 'confidence', 'review_required'])
                    ->map(fn ($answer) => [
                        'question_id' => $answer->question_id, 'status' => $answer->status,
                        'answer_text' => $answer->answer_text, 'evidence_message_id' => $answer->evidence_message_id,
                        'confidence' => $answer->confidence, 'review_required' => (bool) $answer->review_required,
                    ]);

                return [
                    'attempt_id' => $call->id, 'attempt_number' => $call->attempt_number,
                    'call_status' => $call->status, 'provider_status' => $call->provider_status,
                    'termination_reason' => $call->termination_reason,
                    'dialed_at' => $call->dialed_at, 'answered_at' => $call->answered_at,
                    'ended_at' => $call->ended_at, 'duration_seconds' => $call->duration_seconds,
                    'extraction_status' => $call->extraction_status ?? 'pending',
                    'event_id' => $call->event_id, 'delivery_status' => $call->delivery_status,
                    'delivery_attempts' => $call->delivery_attempts, 'delivery_error' => $call->delivery_error,
                    'delivered_at' => $call->delivered_at, 'answers' => $answers,
                ];
            });

        return response()->json([
            'source' => $application->source, 'application_id' => $application->application_id,
            'request_id' => $application->request_id,
            'question_set' => ['id' => $application->question_set_id, 'version' => $application->question_set_version],
            'calls' => $calls,
        ]);
    }

    public function transcript(int $attempt): JsonResponse
    {
        abort_unless(DB::table('career_call_attempts')->where('id', $attempt)->exists(), 404);

        return response()->json([
            'attempt_id' => $attempt,
            'messages' => DB::table('career_call_messages')->where('career_call_attempt_id', $attempt)
                ->orderBy('id')->get(['id', 'speaker', 'message', 'spoken_at']),
        ]);
    }
}
