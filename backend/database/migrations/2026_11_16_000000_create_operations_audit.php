<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Submodule: operations (OPERATIONS-01). Records safe operational actions and outcomes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_operations_audit', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 60);
            $table->string('resource_type', 40);
            $table->unsignedBigInteger('resource_id');
            $table->string('actor', 30);
            $table->json('details_json');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['resource_type', 'resource_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_operations_audit');
    }
};
