<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Submodule: result-delivery (DELIVERY-01). Durable, idempotent callback outbox.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_result_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_call_attempt_id')->unique()->constrained('career_call_attempts')->cascadeOnDelete();
            $table->uuid('event_id')->unique();
            $table->json('payload_json');
            $table->string('status', 30);
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_result_deliveries');
    }
};
