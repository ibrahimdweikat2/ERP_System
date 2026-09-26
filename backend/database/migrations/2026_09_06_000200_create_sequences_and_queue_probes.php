<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->id();
            $t->string('document_type', 60)->unique();
            $t->string('prefix', 20);
            $t->string('year_pattern', 10)->default('Y');
            $t->unsignedBigInteger('next_number')->default(1);
            $t->unsignedTinyInteger('padding')->default(6);
            $t->string('reset_policy', 20)->default('yearly');
            $t->timestamps();
        });
        Schema::create('document_sequence_counters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_sequence_id')->constrained()->restrictOnDelete();
            $t->string('period_key', 10);
            $t->unsignedBigInteger('next_number');
            $t->unique(['document_sequence_id', 'period_key'], 'sequence_period_unique');
        });
        Schema::create('queue_probe_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('queue', 30);
            $t->timestamp('dispatched_at');
            $t->timestamp('completed_at')->nullable();
            $t->unsignedInteger('completion_count')->default(0);
        });
        Schema::table('jobs', fn (Blueprint $t) => $t->index(['queue', 'reserved_at', 'available_at'], 'jobs_polling_index'));
    }

    public function down(): void
    {
        Schema::table('jobs', fn (Blueprint $t) => $t->dropIndex('jobs_polling_index'));
        Schema::dropIfExists('queue_probe_runs');
        Schema::dropIfExists('document_sequence_counters');
        Schema::dropIfExists('document_sequences');
    }
};
