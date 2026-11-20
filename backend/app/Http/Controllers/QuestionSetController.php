<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Submodule: conversation (CONVERSATION-01). Publishes immutable recruitment question versions. */
class QuestionSetController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['sometimes', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'question_set_id' => ['required', 'string', 'max:120'],
            'version' => ['required', 'string', 'max:40'],
            'questions' => ['required', 'array', 'min:1', 'max:10'],
            'questions.*' => ['required', 'array:id,text'],
            'questions.*.id' => ['required', 'string', 'max:80', 'distinct'],
            'questions.*.text' => ['required', 'string', 'max:600'],
        ]);
        $data['source'] ??= 'career';
        $key = ['source' => $data['source'], 'question_set_id' => $data['question_set_id'], 'version' => $data['version']];
        $questions = array_values(array_map(fn (array $question) => [
            'id' => $question['id'], 'text' => $question['text'],
        ], $data['questions']));
        $hash = hash('sha256', json_encode($questions, JSON_THROW_ON_ERROR));
        $existing = DB::table('career_question_sets')->where($key)->first();
        if ($existing) {
            return $this->existingResponse($existing, $hash);
        }

        try {
            DB::table('career_question_sets')->insert([
                ...$key, 'questions' => json_encode($questions, JSON_THROW_ON_ERROR),
                'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $existing = DB::table('career_question_sets')->where($key)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->existingResponse($existing, $hash);
        }

        return response()->json([...$key, 'questions' => $questions], 201);
    }

    private function existingResponse(object $existing, string $hash): JsonResponse
    {
        if (! hash_equals($existing->payload_hash, $hash)) {
            return response()->json(['message' => 'This question-set version already exists with different questions.'], 409);
        }

        return response()->json([
            'source' => $existing->source, 'question_set_id' => $existing->question_set_id,
            'version' => $existing->version, 'questions' => json_decode($existing->questions, true),
        ]);
    }
}
