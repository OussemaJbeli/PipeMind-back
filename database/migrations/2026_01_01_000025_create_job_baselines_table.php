<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Normal" per project, per job, per branch. A 10-minute build is fine for one
 * project and alarming for another.
 *
 * Computed from SUCCESSFUL runs only, with a minimum of 10 samples.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_baselines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('job_name', 190);
            $table->string('ref', 255)->default('*');
            $table->integer('sample_count')->default(0);
            $table->smallInteger('window_days')->default(30);
            $table->decimal('mean_duration_seconds', 10, 2)->nullable();
            $table->decimal('stddev_duration', 10, 2)->nullable();
            $table->decimal('median_duration_seconds', 10, 2)->nullable();
            // MAD is the PRIMARY spread measure. One dead-runner outlier inflates
            // stddev enough to hide every genuine anomaly for a month.
            $table->decimal('mad_duration', 10, 2)->nullable();
            $table->decimal('p95_duration_seconds', 10, 2)->nullable();
            $table->decimal('mean_memory_mb', 10, 2)->nullable();
            $table->decimal('failure_rate', 5, 4)->default(0);
            $table->decimal('retry_rate', 5, 4)->default(0);
            $table->timestampTz('last_computed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['project_id', 'job_name', 'ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_baselines');
    }
};
