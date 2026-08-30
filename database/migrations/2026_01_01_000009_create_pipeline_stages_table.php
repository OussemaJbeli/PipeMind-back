<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->smallInteger('position')->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->smallInteger('jobs_count')->default(0);
            $table->timestampsTz();
            $table->unique(['pipeline_id', 'name']);
        });

        DB::statement("ALTER TABLE pipeline_stages ADD CONSTRAINT chk_stages_status
            CHECK (status IN ('pending','running','success','failed','canceled','skipped','manual'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_stages');
    }
};
