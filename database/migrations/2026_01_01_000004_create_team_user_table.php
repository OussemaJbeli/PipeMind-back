<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('joined_at')->useCurrent();
            $table->timestampsTz();
            $table->unique(['team_id', 'user_id']);
            $table->index('user_id', 'idx_team_user_user');
        });

        DB::statement("ALTER TABLE team_user ADD CONSTRAINT chk_team_user_role
            CHECK (role IN ('owner','admin','member','viewer'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('team_user');
    }
};
