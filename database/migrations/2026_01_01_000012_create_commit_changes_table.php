<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commit_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('file_path', 500);
            $table->string('change_type', 12);
            $table->string('old_path', 500)->nullable();
            $table->integer('additions')->default(0);
            $table->integer('deletions')->default(0);
            $table->string('language', 40)->nullable();
            // Computed at ingest from config('pipemind.file_signals'). Highest-signal
            // features for root-cause correlation.
            $table->boolean('is_config')->default(false);
            $table->boolean('is_dependency')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['pipeline_id', 'file_path']);
            $table->index('pipeline_id', 'idx_changes_pipeline');
        });

        DB::statement("ALTER TABLE commit_changes ADD CONSTRAINT chk_changes_type
            CHECK (change_type IN ('added','modified','deleted','renamed','copied'))");
        DB::statement('CREATE INDEX idx_changes_signals ON commit_changes(pipeline_id) WHERE is_config OR is_dependency');
    }

    public function down(): void
    {
        Schema::dropIfExists('commit_changes');
    }
};
