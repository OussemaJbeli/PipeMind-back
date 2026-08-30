<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Breaks the projects <-> ai_providers <-> pipelines circular dependency.
 * Both target tables are created after projects, so these FKs cannot be declared
 * inline — no migration ordering satisfies all three at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->foreign('ai_provider_id')->references('id')->on('ai_providers')->nullOnDelete();
            $table->foreign('last_pipeline_id')->references('id')->on('pipelines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropForeign(['ai_provider_id']);
            $table->dropForeign(['last_pipeline_id']);
        });
    }
};
