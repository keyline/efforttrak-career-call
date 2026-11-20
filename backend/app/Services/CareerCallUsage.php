<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CareerCallUsage
{
    public static function estimate(array $usage): array
    {
        $transcription = $usage['transcription'] ?? [];
        $openai = null;
        if (($transcription['model'] ?? '') === 'gpt-4o-transcribe') {
            $input = (int) ($transcription['input_tokens'] ?? 0);
            $output = (int) ($transcription['output_tokens'] ?? 0);
            $seconds = (float) ($transcription['seconds'] ?? 0);
            if ($input || $output || $seconds) {
                $openai = $input || $output ? ($input * 2.5 + $output * 10) / 1000000 : $seconds * 0.006 / 60;
            }
        }
        foreach (['callback', 'extraction'] as $step) {
            $part = $usage[$step] ?? [];
            if (($part['model'] ?? '') === 'gpt-4o-mini') {
                $input = (int) ($part['input_tokens'] ?? 0);
                $output = (int) ($part['output_tokens'] ?? 0);
                if ($input || $output) {
                    $openai = ($openai ?? 0) + ($input * 0.15 + $output * 0.60) / 1000000;
                }
            }
        }
        $eleven = $usage['elevenlabs'] ?? [];
        $elevenCost = ($eleven['model'] ?? '') === 'eleven_v4' && ! empty($eleven['characters'])
            ? (int) $eleven['characters'] * 0.08 / 1000 : null;

        return [
            'openai_usd' => $openai === null ? null : round($openai, 6),
            'elevenlabs_usd' => $elevenCost === null ? null : round($elevenCost, 6),
            'total_ai_usd' => $openai === null && $elevenCost === null ? null : round(($openai ?? 0) + ($elevenCost ?? 0), 6),
            'metering_complete' => $openai === null || array_key_exists('extraction', $usage),
            'rates_as_of' => '2026-11-18',
        ];
    }

    public static function merge(int $attemptId, array $usage): void
    {
        DB::transaction(function () use ($attemptId, $usage) {
            $call = DB::table('career_call_attempts')->where('id', $attemptId)->lockForUpdate()->first(['usage_json']);
            if (! $call) {
                return;
            }
            $current = json_decode($call->usage_json ?: '{}', true) ?: [];
            DB::table('career_call_attempts')->where('id', $attemptId)->update([
                'usage_json' => json_encode(array_replace($current, $usage), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }
}
