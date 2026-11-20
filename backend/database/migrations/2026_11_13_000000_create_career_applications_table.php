<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Submodule: application-intake (INTAKE-01). Stores incoming application and consent evidence.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->string('source', 80);
            $table->string('application_id', 120);
            $table->string('job_id', 120);
            $table->string('job_title', 200);
            $table->string('applicant_name', 160);
            $table->string('phone', 16);
            $table->string('language', 20);
            $table->string('question_set_id', 120);
            $table->string('question_set_version', 40);
            $table->boolean('consent_granted');
            $table->timestampTz('consent_captured_at')->nullable();
            $table->string('consent_method', 80)->nullable();
            $table->string('consent_text_version', 80)->nullable();
            $table->timestampTz('submitted_at');
            $table->string('state', 40);
            $table->char('payload_hash', 64);
            $table->timestamps();

            $table->unique(['source', 'application_id']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_applications');
    }
};
