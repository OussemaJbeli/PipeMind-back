<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every model call, billed or not — including cache hits at cost 0 and failures.
 * Answers: what did this cost, where did the time go, how often did the cache save
 * us, and how often did we actually need the LLM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('failure_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('analysis_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 24);
            $table->string('provider', 30);
            $table->string('model', 120);
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->integer('latency_ms')->nullable();
            $table->boolean('cache_hit')->default(false);
            $table->string('status', 20)->default('success');
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // Serves the month-to-date budget query as a range scan:
            //   WHERE team_id = ? AND created_at >= date_trunc('month', now())
            // Do NOT add a date_trunc() expression index — it is redundant, and
            // Postgres rejects it because date_trunc() on timestamptz is STABLE,
            // not IMMUTABLE (the result depends on the session TimeZone).
            $table->index(['team_id', 'created_at'], 'idx_ai_requests_team_created');
        });

        DB::statement('ALTER TABLE ai_requests ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE ai_requests ADD CONSTRAINT chk_ai_requests_operation
            CHECK (operation IN ('analyze','classify','embed','similar','recommend','chat','summarize','patch'))");
        DB::statement("ALTER TABLE ai_requests ADD CONSTRAINT chk_ai_requests_status
            CHECK (status IN ('success','error','timeout','rate_limited','budget_exceeded'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
