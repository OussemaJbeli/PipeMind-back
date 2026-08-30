<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every claim points at something real via source_ref: "job_logs#L1294", a file
 * path, or "failure:<uuid>". An unverifiable claim is not evidence — this table is
 * what stops the analysis panel being a wall of assertions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->text('content');
            $table->string('source_ref', 500)->nullable();
            $table->integer('line_number')->nullable();
            $table->foreignId('related_failure_id')->nullable()->constrained('failures')->nullOnDelete();
            $table->decimal('weight', 4, 3)->default(0.5);
            $table->smallInteger('position')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['analysis_id', 'position'], 'idx_evidence_analysis');
        });

        DB::statement("ALTER TABLE analysis_evidence ADD CONSTRAINT chk_evidence_type
            CHECK (type IN ('log_line','changed_file','historical_failure','metric','config','dependency','commit','doc'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_evidence');
    }
};
