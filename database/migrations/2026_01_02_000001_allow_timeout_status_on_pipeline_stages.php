<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pipeline_stages omitted 'timeout' while pipelines and pipeline_jobs allow it.
 *
 * A stage rolls up to its worst job, so a stage containing a timed-out job is a
 * timeout — and the inconsistency made ingestion fail outright on a real GitLab
 * payload. Aligning the three tables removes the whole class of bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pipeline_stages DROP CONSTRAINT IF EXISTS chk_stages_status');
        DB::statement("ALTER TABLE pipeline_stages ADD CONSTRAINT chk_stages_status
            CHECK (status IN ('pending','running','success','failed','canceled','skipped','manual','timeout'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pipeline_stages DROP CONSTRAINT IF EXISTS chk_stages_status');
        DB::statement("ALTER TABLE pipeline_stages ADD CONSTRAINT chk_stages_status
            CHECK (status IN ('pending','running','success','failed','canceled','skipped','manual'))");
    }
};
