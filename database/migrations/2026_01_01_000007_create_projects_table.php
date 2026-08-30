<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->text('description')->nullable();
            $table->string('external_id', 190)->nullable();
            $table->string('external_path', 255)->nullable();
            $table->string('repository_url', 500)->nullable();
            $table->string('web_url', 500)->nullable();
            $table->string('default_branch', 120)->default('main');
            $table->string('icon', 40)->default('code');
            $table->string('color', 9)->default('#A9E831');
            $table->jsonb('tech_stack')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_analyze')->default(true);
            $table->jsonb('analyze_on_branches')->default('["*"]');
            // FK added late — ai_providers is created after this table.
            $table->unsignedBigInteger('ai_provider_id')->nullable();
            $table->jsonb('settings')->default('{}');
            // Denormalised: the workspace grid needs 4 aggregates per card over a
            // forever-growing table. Refreshed on pipeline completion + every 5 min.
            $table->integer('pipelines_count')->default(0);
            $table->decimal('success_rate', 5, 2)->default(0);
            $table->integer('failures_today')->default(0);
            $table->unsignedBigInteger('last_pipeline_id')->nullable();
            $table->timestampTz('last_pipeline_at')->nullable();
            // "Right now", deliberately different from the 30-day success_rate.
            $table->string('health_status', 20)->default('unknown');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['team_id', 'slug']);
            $table->unique(['integration_id', 'external_id']);
            $table->index('uuid', 'idx_projects_uuid');
        });

        DB::statement('ALTER TABLE projects ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE projects ADD CONSTRAINT chk_projects_health
            CHECK (health_status IN ('healthy','degraded','failing','unknown'))");
        DB::statement('CREATE INDEX idx_projects_team_active ON projects(team_id, is_active) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX idx_projects_last_pipeline ON projects(last_pipeline_at DESC NULLS LAST)');
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
