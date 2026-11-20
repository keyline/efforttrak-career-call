<?php

namespace Tests\Unit;

use App\Services\CareerCallUsage;
use PHPUnit\Framework\TestCase;

class CareerCallUsageTest extends TestCase
{
    public function test_it_estimates_only_metered_ai_steps(): void
    {
        $estimate = CareerCallUsage::estimate([
            'transcription' => ['model' => 'gpt-4o-transcribe', 'input_tokens' => 1000, 'output_tokens' => 100],
            'extraction' => ['model' => 'gpt-4o-mini', 'input_tokens' => 2000, 'output_tokens' => 200],
            'elevenlabs' => ['model' => 'eleven_v4', 'characters' => 1000],
        ]);
        $this->assertSame(0.00392, $estimate['openai_usd']);
        $this->assertSame(0.08, $estimate['elevenlabs_usd']);
        $this->assertSame(0.08392, $estimate['total_ai_usd']);
        $this->assertTrue($estimate['metering_complete']);
        $this->assertNull(CareerCallUsage::estimate([])['total_ai_usd']);
        $this->assertFalse(CareerCallUsage::estimate(['transcription' => [
            'model' => 'gpt-4o-transcribe', 'input_tokens' => 100,
        ]])['metering_complete']);
    }
}
