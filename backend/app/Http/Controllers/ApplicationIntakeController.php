<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchCareerCall;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Submodules: application-intake (INTAKE-01), call-dispatch (DISPATCH-01).
 * Validates career application events and queues eligible records for dispatch.
 */
class ApplicationIntakeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['sometimes', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'application_id' => ['required', 'string', 'max:120'],
            'job_id' => ['required', 'string', 'max:120'],
            'job_title' => ['required', 'string', 'max:200'],
            'applicant_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'language' => ['required', 'string', 'max:20', 'regex:/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/'],
            'question_set_id' => ['required', 'string', 'max:120'],
            'question_set_version' => ['required', 'string', 'max:40'],
            'consent' => ['required', 'array'],
            'consent.granted' => ['required', 'boolean'],
            'consent.captured_at' => ['required_if:consent.granted,true', 'nullable', 'date'],
            'consent.method' => ['required_if:consent.granted,true', 'nullable', 'string', 'max:80'],
            'consent.text_version' => ['nullable', 'string', 'max:80'],
            'do_not_call' => ['sometimes', 'boolean'],
            'submitted_at' => ['required', 'date'],
            'call_script' => ['sometimes', 'array:introduction,availability_question,introduction_statement,questions,unavailable,callback_confirmation,callback_scheduled,callback_declined,conclusion'],
            'call_script.unavailable' => ['sometimes', 'string', 'max:1500'],
            'call_script.introduction' => ['required_with:call_script', 'string', 'max:1500'],
            'call_script.availability_question' => ['sometimes', 'string', 'max:600'],
            'call_script.introduction_statement' => ['sometimes', 'string', 'max:1500'],
            'call_script.callback_confirmation' => ['sometimes', 'string', 'max:600'],
            'call_script.callback_scheduled' => ['sometimes', 'string', 'max:600'],
            'call_script.callback_declined' => ['sometimes', 'string', 'max:600'],
            'call_script.conclusion' => ['required_with:call_script', 'string', 'max:1000'],
            'call_script.questions' => ['required_with:call_script', 'array', 'min:1', 'max:10'],
            'call_script.questions.*' => ['required', 'array:id,text,ask_if'],
            'call_script.questions.*.id' => ['required', 'string', 'max:80', 'distinct'],
            'call_script.questions.*.text' => ['required', 'string', 'max:600'],
            'call_script.questions.*.ask_if' => ['sometimes', 'in:q1_yes'],
        ]);

        $data['source'] ??= 'career';
        $data['do_not_call'] ??= false;
        $this->sortKeys($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $key = ['source' => $data['source'], 'application_id' => $data['application_id']];
        $existing = DB::table('career_applications')->where($key)->first();
        if ($existing) {
            return $this->existingResponse($existing, $hash);
        }

        // The source application ID may change after a candidate resubmits. Enforce
        // the three-month rule again at the call-service boundary before queueing.
        $duplicate = DB::table('career_applications')
            ->where('source', $data['source'])
            ->where('job_id', $data['job_id'])
            ->where('phone', $data['phone'])
            ->where('created_at', '>=', now()->subMonths(3))
            ->orderBy('id')
            ->first();
        if ($duplicate) {
            return response()->json([
                'request_id' => $duplicate->request_id,
                'state' => 'duplicate',
            ], 200);
        }

        $requestId = (string) Str::uuid();
        $state = ! $data['consent']['granted'] ? 'consent_missing' : ($data['do_not_call'] ? 'do_not_call' : 'queued');

        try {
            DB::table('career_applications')->insert([
                ...$key,
                'request_id' => $requestId,
                'job_id' => $data['job_id'],
                'job_title' => $data['job_title'],
                'applicant_name' => $data['applicant_name'],
                'phone' => $data['phone'],
                'language' => $data['language'],
                'question_set_id' => $data['question_set_id'],
                'question_set_version' => $data['question_set_version'],
                'consent_granted' => $data['consent']['granted'],
                'consent_captured_at' => $data['consent']['captured_at'] ?? null,
                'consent_method' => $data['consent']['method'] ?? null,
                'consent_text_version' => $data['consent']['text_version'] ?? null,
                'do_not_call' => $data['do_not_call'],
                'submitted_at' => $data['submitted_at'],
                'state' => $state,
                'call_script_json' => isset($data['call_script']) ? json_encode($data['call_script'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                'payload_hash' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // A concurrent request may have inserted the same source/application ID.
            $existing = DB::table('career_applications')->where($key)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->existingResponse($existing, $hash);
        }

        if ($state === 'queued') {
            $applicationId = DB::table('career_applications')->where($key)->value('id');
            DispatchCareerCall::dispatch((int) $applicationId);
        }

        return response()->json(['request_id' => $requestId, 'state' => $state], 201);
    }

    private function existingResponse(object $existing, string $hash): JsonResponse
    {
        if (! hash_equals($existing->payload_hash, $hash)) {
            return response()->json(['message' => 'An application with this source and application_id already exists with different data.'], 409);
        }

        return response()->json(['request_id' => $existing->request_id, 'state' => $existing->state], 200);
    }

    private function sortKeys(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortKeys($item);
            }
        }
        unset($item);
        ksort($value);
    }
}
