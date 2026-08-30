<?php

use App\Enums\JobStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('pipeline_stages')->nullOnDelete();
            $table->string('external_id', 190);
            $table->string('name', 190);
            $table->string('stage_name', 120);
            $table->smallInteger('position')->default(0);
            $table->string('status', 20)->default('pending');
            // The provider's own reason string. GitLab reports a timeout as a plain
            // 'failed'; only failure_reason distinguishes it, and timeouts matter
            // because they are usually transient.
            $table->string('failure_reason', 80)->nullable();
            $table->smallInteger('exit_code')->nullable();
            $table->boolean('allow_failure')->default(false);
            $table->boolean('is_retryable')->default(true);
            $table->smallInteger('attempt')->default(1);
            $table->string('runner_name', 190)->nullable();
            $table->jsonb('runner_tags')->default('[]');
            $table->string('image', 255)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->integer('queue_seconds')->nullable();
            // Resource telemetry, when the provider exposes it. Feeds anomaly detection.
            $table->integer('peak_memory_mb')->nullable();
            $table->integer('cpu_seconds')->nullable();
            $table->string('web_url', 500)->nullable();
            $table->boolean('log_fetched')->default(false);
            $table->jsonb('raw_payload')->nullable();
            $table->timestampsTz();

            $table->unique(['pipeline_id', 'external_id']);
            $table->index(['pipeline_id', 'position'], 'idx_jobs_pipeline');
        });

        DB::statement('ALTER TABLE pipeline_jobs ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE pipeline_jobs ADD CONSTRAINT chk_jobs_status
            CHECK (status IN ('".implode("','", JobStatus::values())."'))");
        DB::statement("CREATE INDEX idx_jobs_failed ON pipeline_jobs(status) WHERE status = 'failed'");
        // Baseline computation reads successful runs only: a job that died at 4s
        // would drag the mean down and hide genuinely slow runs.
        DB::statement("CREATE INDEX idx_jobs_name_duration ON pipeline_jobs(name, duration_seconds) WHERE status = 'success'");
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_jobs');
    }
};
