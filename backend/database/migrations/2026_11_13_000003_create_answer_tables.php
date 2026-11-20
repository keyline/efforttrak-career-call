<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Submodule: answers (ANSWERS-01). Stores call termination, extraction runs, and cited question answers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('career_call_attempts', function (Blueprint $table) {
            $table->string('termination_reason', 40)->nullable();
        });

        Schema::create('career_answer_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_call_attempt_id')->unique()->constrained('career_call_attempts')->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('model', 80)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->unsignedSmallInteger('run_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('career_call_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_call_attempt_id')->constrained('career_call_attempts')->cascadeOnDelete();
            $table->string('question_id', 80);
            $table->string('status', 20);
            $table->text('answer_text')->nullable();
            $table->foreignId('evidence_message_id')->nullable()->constrained('career_call_messages')->nullOnDelete();
            $table->string('confidence', 20)->nullable();
            $table->boolean('review_required')->default(false);
            $table->timestamps();
            $table->unique(['career_call_attempt_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_call_answers');
        Schema::dropIfExists('career_answer_extractions');
        Schema::table('career_call_attempts', function (Blueprint $table) {
            $table->dropColumn('termination_reason');
        });
    }
};
