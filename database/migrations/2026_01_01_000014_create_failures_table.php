<?php

use App\Enums\FailureCategory;
use App\Enums\FailureStatus;
use App\Enums\Severity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failures', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('pipeline_jobs')->nullOnDelete();
            $table->foreignId('signature_id')->nullable()->constrained('failure_signatures')->nullOnDelete();
            $table->string('status', 20)->default('detected');
            // A deterministic rule, not an AI decision: it must be stable and
            // explainable before any model has run.
            $table->string('severity', 10)->default('medium');
            $table->string('category', 30)->default('UNKNOWN');
            $table->string('subcategory', 60)->nullable();
            $table->string('stage_name', 120)->nullable();
            $table->string('job_name', 190)->nullable();
            $table->text('error_message')->nullable();
            $table->string('error_type', 120)->nullable();
            $table->smallInteger('exit_code')->nullable();
            // Flaky failures skip analysis: analysing the same flake 20 times is the
            // fastest way to exhaust a budget for zero insight.
            $table->boolean('is_flaky')->default(false);
            $table->boolean('is_transient')->default(false);
            $table->integer('occurrence_index')->default(1);
            $table->timestampTz('failed_at');
            $table->timestampTz('detected_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_type', 20)->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('resolution_commit_sha', 64)->nullable();
            $table->integer('time_to_resolution_seconds')->nullable();
            $table->timestampsTz();

            $table->index(['project_id', 'failed_at'], 'idx_failures_project_failed');
            $table->index(['team_id', 'status'], 'idx_failures_team_status');
            $table->index(['signature_id', 'failed_at'], 'idx_failures_signature');
            $table->index(['project_id', 'category', 'failed_at'], 'idx_failures_category');
        });

        DB::statement('ALTER TABLE failures ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE failures ADD CONSTRAINT chk_failures_status
            CHECK (status IN ('".implode("','", FailureStatus::values())."'))");
        DB::statement("ALTER TABLE failures ADD CONSTRAINT chk_failures_severity
            CHECK (severity IN ('".implode("','", Severity::values())."'))");
        DB::statement("ALTER TABLE failures ADD CONSTRAINT chk_failures_category
            CHECK (category IN ('".implode("','", FailureCategory::values())."'))");
        DB::statement("ALTER TABLE failures ADD CONSTRAINT chk_failures_resolution
            CHECK (resolution_type IS NULL OR resolution_type IN
                ('fixed','retried','ignored','auto_remediated','flaky','unresolved'))");
        DB::statement('CREATE INDEX idx_failures_unresolved ON failures(project_id, failed_at DESC) WHERE resolved_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('failures');
    }
};
