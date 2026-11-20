<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Submodule: call-dispatch (DISPATCH-01). Adds eligibility flags and durable Vobiz attempt history.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('career_applications', function (Blueprint $table) {
            $table->boolean('do_not_call')->default(false);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('last_call_reason', 80)->nullable();
        });

        Schema::create('career_call_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_application_id')->constrained('career_applications')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 30);
            $table->string('provider_call_id', 120)->nullable()->unique();
            $table->string('provider_status', 80)->nullable();
            $table->timestampTz('dialed_at')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->unique(['career_application_id', 'attempt_number']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_call_attempts');
        Schema::table('career_applications', function (Blueprint $table) {
            $table->dropColumn(['do_not_call', 'next_attempt_at', 'last_call_reason']);
        });
    }
};
