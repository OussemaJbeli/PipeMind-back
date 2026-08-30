<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('provider', 30);
            $table->string('model', 120);
            $table->string('base_url', 255)->nullable();
            // encrypted cast + $hidden. Passed per-request to the AI service as an
            // override, so that service stores no team's credentials at rest.
            $table->text('api_key')->nullable();
            $table->boolean('is_default')->default(false);
            // true = logs never leave your infrastructure. A team with
            // privacy_mode=local_only can only use these.
            $table->boolean('is_local')->default(false);
            $table->integer('max_tokens')->default(4096);
            $table->decimal('temperature', 3, 2)->default(0.20);
            // Rates live in the row, not in code: pricing changes, and a hardcoded
            // number becomes a lie within a month.
            $table->decimal('input_cost_per_1k', 10, 6)->default(0);
            $table->decimal('output_cost_per_1k', 10, 6)->default(0);
            $table->string('status', 20)->default('untested');
            $table->timestampTz('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE ai_providers ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE ai_providers ADD CONSTRAINT chk_ai_providers_provider
            CHECK (provider IN ('gemini','openai','anthropic','ollama','openai_compatible','azure_openai','stub'))");
        DB::statement("ALTER TABLE ai_providers ADD CONSTRAINT chk_ai_providers_status
            CHECK (status IN ('untested','active','error','disabled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
