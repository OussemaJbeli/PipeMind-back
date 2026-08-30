<?php

use App\Enums\ActionType;
use App\Enums\RemediationStatus;
use App\Enums\Severity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remediations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('failure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recommendation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action_type', 30);
            $table->string('risk', 10);
            $table->string('policy_decision', 20);
            $table->string('policy_reason', 255)->nullable();
            $table->string('status', 20)->default('pending_approval');
            $table->jsonb('payload')->default('{}');
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            // NULL requester = system/AI initiated.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            // Approvals expire: a stale approval executing against a moved-on
            // codebase is a hazard.
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('resulting_pipeline_id')->nullable()->constrained('pipelines')->nullOnDelete();
            // Closes the learning loop: a retry that goes green is evidence the
            // resolution works, and promotes the signature to is_known.
            $table->boolean('outcome_success')->nullable();
            $table->jsonb('audit')->default('[]');
            $table->timestampsTz();

            $table->index(['project_id', 'created_at'], 'idx_remediations_project');
        });

        DB::statement('ALTER TABLE remediations ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE remediations ADD CONSTRAINT chk_remediations_status
            CHECK (status IN ('".implode("','", RemediationStatus::values())."'))");
        DB::statement("ALTER TABLE remediations ADD CONSTRAINT chk_remediations_decision
            CHECK (policy_decision IN ('auto_allowed','requires_approval','forbidden'))");
        DB::statement("ALTER TABLE remediations ADD CONSTRAINT chk_remediations_action
            CHECK (action_type IN ('".implode("','", ActionType::values())."'))");
        DB::statement("ALTER TABLE remediations ADD CONSTRAINT chk_remediations_risk
            CHECK (risk IN ('".implode("','", Severity::values())."'))");
        DB::statement("CREATE INDEX idx_remediations_pending ON remediations(team_id, status)
            WHERE status = 'pending_approval'");
    }

    public function down(): void
    {
        Schema::dropIfExists('remediations');
    }
};
