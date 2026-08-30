<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('chunk_index');
            $table->text('content');
            $table->smallInteger('token_count')->nullable();
            $table->string('model', 120)->default('all-MiniLM-L6-v2');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['document_id', 'chunk_index']);
            $table->index('team_id', 'idx_knowledge_chunks_team');
        });

        DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding vector(384) NOT NULL');
        DB::statement('CREATE INDEX idx_knowledge_chunks_hnsw ON knowledge_chunks
            USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64)');
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
