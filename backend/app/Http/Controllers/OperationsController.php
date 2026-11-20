<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverCareerResult;
use App\Jobs\ExtractCareerAnswers;
use App\Services\OperationsAudit;
use App\Services\CareerCallUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Submodule: operations (OPERATIONS-01). Protected status, configuration, retry, and audit endpoints. */
class OperationsController extends Controller
{
    public function calls(Request $request): JsonResponse
    {
        $filter = $request->validate(['status' => ['sometimes', Rule::in([
            'dialing', 'answered', 'completed', 'no_answer', 'busy', 'failed', 'cancelled',
        ])]]);
        $rows = DB::table('career_call_attempts as calls')
            ->join('career_applications as applications', 'applications.id', '=', 'calls.career_application_id')
            ->leftJoin('career_answer_extractions as extraction', 'extraction.career_call_attempt_id', '=', 'calls.id')
            ->leftJoin('career_result_deliveries as delivery', 'delivery.career_call_attempt_id', '=', 'calls.id')
            ->leftJoinSub(DB::table('career_call_answers')->selectRaw('career_call_attempt_id, SUM(review_required) as review_count')
                ->groupBy('career_call_attempt_id'), 'review', 'review.career_call_attempt_id', '=', 'calls.id')
            ->select('calls.id', 'calls.attempt_number', 'calls.status', 'calls.termination_reason', 'calls.usage_json',
                'calls.dialed_at', 'calls.answered_at', 'calls.ended_at', 'applications.source',
                'applications.application_id', 'extraction.status as extraction_status',
                'delivery.status as delivery_status', 'review.review_count')
            ->orderByDesc('calls.id');
        if (isset($filter['status'])) {
            $rows->where('calls.status', $filter['status']);
        }

        return response()->json($rows->paginate(25)->through(function ($row) {
            $row->usage = json_decode($row->usage_json ?: '{}', true);
            $row->ai_cost_estimate = CareerCallUsage::estimate($row->usage ?: []);
            unset($row->usage_json);
            return $row;
        }));
    }

    public function deliveries(Request $request): JsonResponse
    {
        $filter = $request->validate(['status' => ['sometimes', Rule::in([
            'pending', 'sending', 'retryable', 'configuration_blocked', 'failed_permanent', 'failed_exhausted', 'delivered',
        ])]]);
        $rows = DB::table('career_result_deliveries as delivery')
            ->join('career_call_attempts as calls', 'calls.id', '=', 'delivery.career_call_attempt_id')
            ->join('career_applications as applications', 'applications.id', '=', 'calls.career_application_id')
            ->select('delivery.id', 'delivery.event_id', 'delivery.status', 'delivery.attempt_count',
                'delivery.next_attempt_at', 'delivery.last_http_status', 'delivery.last_error_code',
                'delivery.delivered_at', 'calls.id as call_attempt_id', 'applications.source', 'applications.application_id')
            ->orderByDesc('delivery.id');
        if (isset($filter['status'])) {
            $rows->where('delivery.status', $filter['status']);
        } else {
            $rows->whereIn('delivery.status', ['retryable', 'configuration_blocked', 'failed_permanent', 'failed_exhausted']);
        }

        return response()->json($rows->paginate(25));
    }

    public function questionSets(Request $request): JsonResponse
    {
        $filter = $request->validate(['source' => ['sometimes', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/']]);
        $query = DB::table('career_question_sets')->select('id', 'source', 'question_set_id', 'version', 'questions', 'created_at')
            ->orderByDesc('id');
        if (isset($filter['source'])) {
            $query->where('source', $filter['source']);
        }

        return response()->json($query->paginate(25)->through(function ($row) {
            $row->questions = json_decode($row->questions, true);

            return $row;
        }));
    }

    public function publishQuestionSet(Request $request, QuestionSetController $sets): JsonResponse
    {
        return DB::transaction(function () use ($request, $sets) {
            $response = $sets->store($request);
            if ($response->status() === 201) {
                $data = $response->getData(true);
                $id = DB::table('career_question_sets')->where('source', $data['source'])
                    ->where('question_set_id', $data['question_set_id'])->where('version', $data['version'])->value('id');
                OperationsAudit::record('question_set_published', 'question_set', (int) $id, 'operations_api', [
                    'question_count' => count($data['questions']),
                ]);
            }

            return $response;
        });
    }

    public function retryDelivery(int $delivery): JsonResponse
    {
        $id = DB::transaction(function () use ($delivery) {
            $row = DB::table('career_result_deliveries')->where('id', $delivery)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless(in_array($row->status, ['retryable', 'configuration_blocked', 'failed_permanent', 'failed_exhausted'], true), 409);
            DB::table('career_result_deliveries')->where('id', $delivery)->update([
                'status' => 'pending', 'attempt_count' => 0, 'next_attempt_at' => now(),
                'last_error_code' => null, 'updated_at' => now(),
            ]);
            OperationsAudit::record('delivery_retry_requested', 'delivery', $delivery, 'operations_api', [
                'previous_status' => $row->status, 'previous_attempts' => $row->attempt_count,
            ]);

            return $delivery;
        });
        DeliverCareerResult::dispatch($id);

        return response()->json(['delivery_id' => $id, 'status' => 'pending'], 202);
    }

    public function retryExtraction(int $attempt): JsonResponse
    {
        DB::transaction(function () use ($attempt) {
            $run = DB::table('career_answer_extractions')->where('career_call_attempt_id', $attempt)->lockForUpdate()->first();
            abort_unless($run, 404);
            abort_unless($run->status === 'failed', 409);
            DB::table('career_answer_extractions')->where('id', $run->id)->update([
                'status' => 'pending', 'run_count' => 0, 'error_code' => null, 'started_at' => null, 'updated_at' => now(),
            ]);
            OperationsAudit::record('extraction_retry_requested', 'call_attempt', $attempt, 'operations_api', [
                'previous_error' => $run->error_code, 'previous_runs' => $run->run_count,
            ]);
        });
        ExtractCareerAnswers::dispatch($attempt);

        return response()->json(['attempt_id' => $attempt, 'extraction_status' => 'pending'], 202);
    }

    public function audit(): JsonResponse
    {
        return response()->json(DB::table('career_operations_audit')->orderByDesc('id')->paginate(50));
    }
}
