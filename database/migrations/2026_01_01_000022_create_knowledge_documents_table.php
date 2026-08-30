<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('title', 255);
            $table->string('source_url', 500)->nullable();
            $table->string('source_path', 500)->nullable();
            $table->text('content');
            $table->char('content_hash', 64);
            $table->integer('token_count')->nullable();
            $table->smallInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('indexed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE knowledge_documents ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE knowledge_documents ADD CONSTRAINT chk_knowledge_type
            CHECK (type IN ('runbook','readme','ci_config','incident','resolution','doc','faq'))");
        DB::statement('CREATE INDEX idx_knowledge_docs_team ON knowledge_documents(team_id, project_id) WHERE is_active = TRUE');
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
