<?php

use App\Enums\FailureCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The deduplication spine. One row per distinct normalised error, per team.
 *
 * The hash column does four jobs at once: deduplication, clustering, LLM response
 * cache key, and the similarity join. It is the highest-leverage column in the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failure_signatures', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->char('hash', 64);
            // Numbers, paths, UUIDs, timestamps and hex replaced by placeholders.
            $table->text('normalized_error');
            $table->text('sample_error');
            $table->string('category', 30)->default('UNKNOWN');
            $table->string('subcategory', 60)->nullable();
            $table->integer('occurrence_count')->default(1);
            $table->smallInteger('projects_affected')->default(1);
            $table->timestampTz('first_seen_at')->useCurrent();
            $table->timestampTz('last_seen_at')->useCurrent();
            // Once confirmed, future matches short-circuit here with no model call.
            $table->boolean('is_known')->default(false);
            $table->text('known_root_cause')->nullable();
            $table->text('known_resolution')->nullable();
            $table->foreignId('resolution_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolution_confirmed_at')->nullable();
            $table->integer('avg_resolution_seconds')->nullable();
            $table->timestampsTz();

            $table->unique(['team_id', 'hash']);
            $table->index(['team_id', 'hash'], 'idx_signatures_team_hash');
        });

        DB::statement('ALTER TABLE failure_signatures ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE failure_signatures ADD CONSTRAINT chk_signatures_category
            CHECK (category IN ('".implode("','", FailureCategory::values())."'))");
        DB::statement('CREATE INDEX idx_signatures_known ON failure_signatures(team_id, is_known) WHERE is_known = TRUE');
        // Trigram: powers the "I remember seeing this error" case in global search.
        DB::statement('CREATE INDEX idx_signatures_trgm ON failure_signatures USING gin (sample_error gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('failure_signatures');
    }
};
