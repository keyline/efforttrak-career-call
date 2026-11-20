<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Submodule: conversation (CONVERSATION-01). Immutable question versions and call transcript turns.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_question_sets', function (Blueprint $table) {
            $table->id();
            $table->string('source', 80);
            $table->string('question_set_id', 120);
            $table->string('version', 40);
            $table->json('questions');
            $table->char('payload_hash', 64);
            $table->timestamps();
            $table->unique(['source', 'question_set_id', 'version'], 'career_question_version_unique');
        });

        Schema::create('career_call_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_call_attempt_id')->constrained('career_call_attempts')->cascadeOnDelete();
            $table->string('speaker', 20);
            $table->text('message');
            $table->timestampTz('spoken_at');
            $table->timestamps();
            $table->index(['career_call_attempt_id', 'spoken_at'], 'career_call_messages_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_call_messages');
        Schema::dropIfExists('career_question_sets');
    }
};
