<?php

use App\Enums\AnalysisStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analyses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('failure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('contract_version', 10)->default('v1');
            $table->string('ai_service_version', 20)->nullable();
            $table->string('category', 30)->nullable();
            $table->string('subcategory', 60)->nullable();
            $table->string('severity', 10)->nullable();
            // Calibrated, not the model's raw self-report: corroboration from the
            // rule engine and from history adjusts it.
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('summary', 500)->nullable();
            $table->text('root_cause')->nullable();
            $table->text('explanation')->nullable();
            $table->boolean('is_transient')->default(false);
            $table->boolean('retry_recommended')->default(false);
            // Provenance: answers "how often did we actually need the LLM?"
            $table->string('classification_source', 20)->nullable();
            $table->decimal('classification_confidence', 4, 3)->nullable();
            $table->boolean('used_rag')->default(false);
            $table->smallInteger('similar_failures_count')->default(0);
            $table->string('model_provider', 30)->nullable();
            $table->string('model_name', 80)->nullable();
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->integer('latency_ms')->nullable();
            $table->boolean('cache_hit')->default(false);
            $table->jsonb('raw_response')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['failure_id', 'created_at'], 'idx_analyses_failure');
            $table->index(['team_id', 'created_at'], 'idx_analyses_team_created');
        });

        DB::statement('ALTER TABLE analyses ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE analyses ADD CONSTRAINT chk_analyses_status
            CHECK (status IN ('".implode("','", AnalysisStatus::values())."'))");
        DB::statement('ALTER TABLE analyses ADD CONSTRAINT chk_analyses_confidence
            CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1)');
        DB::statement("ALTER TABLE analyses ADD CONSTRAINT chk_analyses_source
            CHECK (classification_source IS NULL OR classification_source IN ('rules','ml','llm','hybrid'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('analyses');
    }
};
