<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('logo_url', 500)->nullable();
            $table->string('plan', 20)->default('free');
            $table->string('privacy_mode', 20)->default('cloud_redacted');
            $table->jsonb('settings')->default('{}');
            $table->decimal('monthly_ai_budget_usd', 10, 2)->default(25.00);
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        DB::statement('ALTER TABLE teams ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE teams ADD CONSTRAINT chk_teams_plan
            CHECK (plan IN ('free','pro','enterprise'))");
        DB::statement("ALTER TABLE teams ADD CONSTRAINT chk_teams_privacy
            CHECK (privacy_mode IN ('cloud_redacted','local_only'))");

        // Deferred until teams exists: breaks the users <-> teams circular dependency.
        Schema::table('users', function (Blueprint $table): void {
            $table->foreign('current_team_id')->references('id')->on('teams')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropForeign(['current_team_id']));
        Schema::dropIfExists('teams');
    }
};
