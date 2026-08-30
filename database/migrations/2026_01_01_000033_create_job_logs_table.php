<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata only. The bytes live in MinIO/S3, immutable and UNREDACTED so analysis
 * stays reproducible. Postgres keeps the path, checksum, redaction bookkeeping and
 * the extracted excerpt — the ~40 lines the UI shows and the LLM sees.
 *
 * Declared last: references projects, pipelines and pipeline_jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->unique()->constrained('pipeline_jobs')->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('storage_disk', 40)->default('logs');
            $table->string('storage_path', 500);
            $table->bigInteger('size_bytes')->default(0);
            $table->integer('line_count')->default(0);
            $table->char('checksum_sha256', 64)->nullable();
            $table->boolean('is_redacted')->default(false);
            $table->smallInteger('redaction_count')->default(0);
            $table->jsonb('redaction_types')->default('[]');
            $table->text('excerpt')->nullable();
            $table->integer('excerpt_start_line')->nullable();
            $table->integer('excerpt_end_line')->nullable();
            $table->text('error_block')->nullable();
            $table->text('stack_trace')->nullable();
            $table->boolean('truncated')->default(false);
            $table->timestampTz('fetched_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->index(['project_id', 'created_at'], 'idx_job_logs_project');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_logs');
    }
};
