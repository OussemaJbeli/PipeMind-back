<?php

use App\Enums\ActionType;
use App\Enums\Severity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deterministic rules. The LLM proposes; this table decides; Laravel executes.
 * A missing policy means FORBIDDEN — fail closed, never default-allow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remediation_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // NULL = team default. A project row overrides it.
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('action_type', 30);
            $table->string('mode', 20)->default('approval');
            $table->string('max_risk', 10)->default('low');
            $table->decimal('min_confidence', 4, 3)->default(0.800);
            $table->smallInteger('max_per_day')->default(5);
            $table->jsonb('allowed_branches')->default('["*"]');
            $table->jsonb('blocked_branches')->default('["main","master","production"]');
            $table->boolean('enabled')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['team_id', 'project_id', 'action_type']);
        });

        DB::statement("ALTER TABLE remediation_policies ADD CONSTRAINT chk_policies_mode
            CHECK (mode IN ('auto','approval','forbidden'))");
        DB::statement("ALTER TABLE remediation_policies ADD CONSTRAINT chk_policies_risk
            CHECK (max_risk IN ('".implode("','", Severity::values())."'))");
        DB::statement("ALTER TABLE remediation_policies ADD CONSTRAINT chk_policies_action
            CHECK (action_type IN ('".implode("','", ActionType::values())."'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('remediation_policies');
    }
};
