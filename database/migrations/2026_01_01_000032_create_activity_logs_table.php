<?php

use App\Enums\ActivityLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Powers the "Recent Activity" feed on both target pages. The `action` vocabulary
 * is a fixed list the frontend maps to icons and colours — unknown actions degrade
 * to a neutral dot rather than crashing, so the backend can add actions safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type', 10)->default('user');
            $table->string('action', 60);
            $table->string('level', 10)->default('info');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->uuid('subject_uuid')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['team_id', 'created_at'], 'idx_activity_team_created');
            $table->index(['project_id', 'created_at'], 'idx_activity_project_created');
        });

        DB::statement('ALTER TABLE activity_logs ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE activity_logs ADD CONSTRAINT chk_activity_actor
            CHECK (actor_type IN ('user','system','ai','provider'))");
        DB::statement("ALTER TABLE activity_logs ADD CONSTRAINT chk_activity_level
            CHECK (level IN ('".implode("','", ActivityLevel::values())."'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
