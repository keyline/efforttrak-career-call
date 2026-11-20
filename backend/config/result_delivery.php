<?php

// Submodule: result-delivery (DELIVERY-01). Fixed callback destination and signing policy.
return [
    'callback_url' => env('CAREER_CALLBACK_URL', ''),
    'callback_secret' => env('CAREER_CALLBACK_SECRET', ''),
    'max_attempts' => (int) env('CAREER_DELIVERY_MAX_ATTEMPTS', 8),
    'include_transcript_reference' => (bool) env('CAREER_INCLUDE_TRANSCRIPT_REFERENCE', false),
];
