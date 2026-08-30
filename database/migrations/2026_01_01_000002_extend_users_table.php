<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('avatar_url', 500)->nullable()->after('password');
            $table->string('job_title', 120)->nullable()->after('avatar_url');
            $table->string('timezone', 64)->default('UTC')->after('job_title');
            $table->string('locale', 10)->default('en')->after('timezone');
            $table->string('theme', 10)->default('dark')->after('locale');
            $table->unsignedBigInteger('current_team_id')->nullable()->after('theme');
            $table->timestampTz('onboarded_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->softDeletesTz();
        });

        DB::statement('UPDATE users SET uuid = uuid_generate_v4() WHERE uuid IS NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN uuid SET NOT NULL');
        DB::statement('ALTER TABLE users ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_theme
            CHECK (theme IN ('dark','light','system'))");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['uuid', 'avatar_url', 'job_title', 'timezone', 'locale',
                'theme', 'current_team_id', 'onboarded_at', 'last_login_at', 'last_login_ip', 'deleted_at']);
        });
    }
};
