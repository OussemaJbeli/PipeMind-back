<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('role', 20)->default('member');
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
            // Re-inviting refreshes the existing row rather than duplicating it.
            $table->unique(['team_id', 'email']);
        });

        DB::statement('ALTER TABLE team_invitations ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE team_invitations ADD CONSTRAINT chk_invitations_role
            CHECK (role IN ('admin','member','viewer'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
