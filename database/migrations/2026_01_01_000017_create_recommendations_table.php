<?php

use App\Enums\ActionType;
use App\Enums\Severity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('failure_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->text('rationale')->nullable();
            $table->string('action_type', 30);
            // Assigned from action_type by code — NEVER taken from the model.
            $table->string('risk', 10)->default('medium');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->jsonb('affected_files')->default('[]');
            $table->text('patch')->nullable();
            $table->jsonb('action_payload')->default('{}');
            $table->smallInteger('position')->default(0);
            $table->string('status', 20)->default('proposed');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->index(['failure_id', 'position'], 'idx_recos_failure');
        });

        DB::statement('ALTER TABLE recommendations ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE recommendations ADD CONSTRAINT chk_recos_action
            CHECK (action_type IN ('".implode("','", ActionType::values())."'))");
        DB::statement("ALTER TABLE recommendations ADD CONSTRAINT chk_recos_risk
            CHECK (risk IN ('".implode("','", Severity::values())."'))");
        DB::statement("ALTER TABLE recommendations ADD CONSTRAINT chk_recos_status
            CHECK (status IN ('proposed','accepted','rejected','applied','failed','expired'))");
        DB::statement("CREATE INDEX idx_recos_status ON recommendations(status) WHERE status = 'proposed'");
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
    }
};
