<?php

// Submodule: call-dispatch (DISPATCH-01). Operator-controlled outbound policy and Vobiz settings.
return [
    'enabled' => (bool) env('CALL_DISPATCH_ENABLED', false),
    'voice_ready' => (bool) env('CAREER_VOICE_READY', false),
    'timezone' => env('CALL_TIMEZONE', 'Asia/Kolkata'),
    'start_hour' => (int) env('CALL_START_HOUR', 9),
    'end_hour' => (int) env('CALL_END_HOUR', 18),
    'days' => array_map('intval', explode(',', env('CALL_DAYS', '1,2,3,4,5,6'))),
    'max_attempts' => (int) env('CALL_MAX_ATTEMPTS', 2),
    'retry_minutes' => (int) env('CALL_RETRY_MINUTES', 60),
    'min_application_age_seconds' => (int) env('CALL_MIN_APPLICATION_AGE_SECONDS', 60),
    'phone_cooldown_hours' => (int) env('CALL_PHONE_COOLDOWN_HOURS', 24),
    'max_calls_per_hour' => (int) env('CALL_MAX_CALLS_PER_HOUR', 30),
    'time_limit_seconds' => (int) env('CALL_TIME_LIMIT_SECONDS', 300),
    'vobiz' => [
        'auth_id' => env('VOBIZ_AUTH_ID', ''),
        'auth_token' => env('VOBIZ_AUTH_TOKEN', ''),
        'caller_id' => env('VOBIZ_CALLER_ID', ''),
        'api_base' => env('VOBIZ_API_BASE', 'https://api.vobiz.ai'),
        'answer_url' => env('VOBIZ_ANSWER_URL', ''),
        'hangup_url' => env('VOBIZ_HANGUP_URL', ''),
        'stream_url' => env('VOBIZ_STREAM_URL', ''),
        'callback_token' => env('VOBIZ_CALLBACK_TOKEN', ''),
        'bridge_token' => env('VOBIZ_BRIDGE_TOKEN', ''),
    ],
];
