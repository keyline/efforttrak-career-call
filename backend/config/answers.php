<?php

// Submodule: answers (ANSWERS-01). OpenAI Responses extraction settings.
return [
    'api_key' => env('OPENAI_API_KEY', ''),
    'model' => env('ANSWER_EXTRACTION_MODEL', 'gpt-4o-mini'),
    'max_runs' => (int) env('ANSWER_EXTRACTION_MAX_RUNS', 3),
];
