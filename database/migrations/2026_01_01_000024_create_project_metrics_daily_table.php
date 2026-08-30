<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly rollup. The project board has eleven data regions; serving them from raw
 * pipelines scans a forever-growing table. Reading a handful of indexed rollup rows
 * stays fast at any history depth — which is why the whole board is one request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_metrics_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->integer('pipelines_total')->default(0);
            $table->integer('pipelines_success')->default(0);
            $table->integer('pipelines_failed')->default(0);
            $table->integer('pipelines_canceled')->default(0);
            $table->integer('pipelines_running')->default(0);
            $table->decimal('success_rate', 5, 2)->default(0);
            $table->integer('avg_duration_seconds')->default(0);
            $table->integer('p50_duration_seconds')->default(0);
            $table->integer('p95_duration_seconds')->default(0);
            $table->integer('failures_count')->default(0);
            $table->integer('failures_resolved')->default(0);
            $table->integer('mttr_seconds')->nullable();
            // {"DATABASE":3,"TEST":1} — drives the donut and the category bar list.
            $table->jsonb('failures_by_category')->default('{}');
            $table->integer('analyses_count')->default(0);
            $table->decimal('ai_cost_usd', 10, 6)->default(0);
            $table->integer('anomalies_count')->default(0);
            $table->timestampsTz();

            $table->unique(['project_id', 'date']);
            $table->index(['project_id', 'date'], 'idx_metrics_project_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_metrics_daily');
    }
};
