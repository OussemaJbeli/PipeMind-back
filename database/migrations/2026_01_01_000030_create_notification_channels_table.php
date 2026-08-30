<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('name', 120);
            // Encrypted: a webhook URL is a credential.
            $table->text('config');
            $table->jsonb('events')->default('["failure.detected","analysis.completed"]');
            $table->string('min_severity', 10)->default('medium');
            $table->boolean('enabled')->default(true);
            $table->timestampTz('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE notification_channels ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE notification_channels ADD CONSTRAINT chk_channels_type
            CHECK (type IN ('slack','teams','email','webhook','discord'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');
    }
};
