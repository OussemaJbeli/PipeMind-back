<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failure_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('failure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_id')->nullable()->constrained('failure_signatures')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // The escape hatch from the one hard-to-reverse decision in the schema:
            // a second model can be backfilled alongside and compared before cutover.
            // DO NOT REMOVE this column.
            $table->string('model', 120)->default('all-MiniLM-L6-v2');
            $table->smallInteger('dimensions')->default(384);
            // Exactly what was embedded — reproducibility.
            $table->text('source_text');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['failure_id', 'model']);
            $table->index('team_id', 'idx_failure_embeddings_team');
        });

        // Laravel's builder has no vector type.
        DB::statement('ALTER TABLE failure_embeddings ADD COLUMN embedding vector(384) NOT NULL');
        // HNSW over IVFFlat: better recall at this scale, and no training step, so
        // the index is correct from the first row.
        DB::statement('CREATE INDEX idx_failure_embeddings_hnsw ON failure_embeddings
            USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64)');
    }

    public function down(): void
    {
        Schema::dropIfExists('failure_embeddings');
    }
};
