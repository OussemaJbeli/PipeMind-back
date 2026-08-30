<?php

use App\Enums\PipelineStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Provider's API identifier. Distinct from iid (the display number).
            $table->string('external_id', 190);
            $table->integer('iid')->nullable();
            $table->string('provider', 20);
            $table->string('status', 20)->default('queued');
            $table->string('source', 30)->default('push');
            $table->string('ref', 255);
            $table->boolean('is_tag')->default(false);
            $table->string('commit_sha', 64)->nullable();
            $table->string('commit_short_sha', 12)->nullable();
            $table->text('commit_message')->nullable();
            $table->string('commit_author_name', 190)->nullable();
            $table->string('commit_author_email', 190)->nullable();
            $table->string('commit_url', 500)->nullable();
            $table->string('web_url', 500)->nullable();
            $table->string('triggered_by', 190)->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->integer('queue_seconds')->nullable();
            $table->smallInteger('jobs_total')->default(0);
            $table->smallInteger('jobs_failed')->default(0);
            $table->smallInteger('jobs_succeeded')->default(0);
            $table->smallInteger('attempt')->default(1);
            $table->foreignId('retry_of_id')->nullable()->constrained('pipelines')->nullOnDelete();
            $table->boolean('has_failure')->default(false);
            $table->jsonb('raw_payload')->nullable();
            $table->timestampsTz();

            // Makes ingestion idempotent: a redelivered webhook updates, never duplicates.
            $table->unique(['project_id', 'external_id']);
            $table->index(['project_id', 'created_at'], 'idx_pipelines_project_created');
            $table->index(['project_id', 'status'], 'idx_pipelines_project_status');
            $table->index(['project_id', 'ref'], 'idx_pipelines_ref');
            $table->index('commit_sha', 'idx_pipelines_commit');
        });

        DB::statement('ALTER TABLE pipelines ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE pipelines ADD CONSTRAINT chk_pipelines_status
            CHECK (status IN ('".implode("','", PipelineStatus::values())."'))");
        DB::statement("ALTER TABLE pipelines ADD CONSTRAINT chk_pipelines_source
            CHECK (source IN ('push','merge_request','schedule','manual','api','tag','web','trigger','unknown'))");
        DB::statement("CREATE INDEX idx_pipelines_running ON pipelines(status) WHERE status IN ('queued','running')");
        DB::statement('CREATE INDEX idx_pipelines_finished ON pipelines(project_id, finished_at DESC) WHERE finished_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('pipelines');
    }
};
