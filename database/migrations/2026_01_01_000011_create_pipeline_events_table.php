<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('integration_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('event_type', 60);
            // X-GitHub-Delivery / X-Gitlab-Event-UUID. The unique index below makes
            // duplicate detection a database concern rather than application logic.
            $table->string('external_delivery_id', 190)->nullable();
            $table->string('external_object_id', 190)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->jsonb('payload');
            // Auth headers are stripped before storage.
            $table->jsonb('headers')->default('{}');
            $table->string('processing_status', 20)->default('pending');
            $table->text('processing_error')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();

            $table->unique(['integration_id', 'external_delivery_id']);
            $table->index(['project_id', 'received_at'], 'idx_events_project_received');
        });

        DB::statement('ALTER TABLE pipeline_events ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE pipeline_events ADD CONSTRAINT chk_events_status
            CHECK (processing_status IN ('pending','processing','processed','failed','skipped','duplicate'))");
        DB::statement("CREATE INDEX idx_events_pending ON pipeline_events(processing_status, received_at)
            WHERE processing_status IN ('pending','failed')");
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_events');
    }
};
