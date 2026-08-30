<?php

use App\Enums\Severity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('pipeline_jobs')->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('severity', 10)->default('medium');
            $table->string('metric_name', 80);
            $table->decimal('observed_value', 14, 4);
            $table->decimal('baseline_value', 14, 4);
            // What the UI shows: "4.1x higher than normal" is readable where
            // "modified z-score 9.2" is not. Both are stored.
            $table->decimal('deviation_ratio', 8, 3)->nullable();
            $table->decimal('z_score', 8, 3)->nullable();
            $table->string('detection_method', 24)->default('zscore');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->jsonb('possible_causes')->default('[]');
            // false_positive is a first-class status: a detector nobody can push
            // back on gets ignored within a week.
            $table->string('status', 20)->default('open');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->timestampTz('detected_at')->useCurrent();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE anomalies ALTER COLUMN uuid SET DEFAULT uuid_generate_v4()');
        DB::statement("ALTER TABLE anomalies ADD CONSTRAINT chk_anomalies_type
            CHECK (type IN ('duration','memory','failure_rate','retry_rate','queue_time','test_count','log_size','flaky_test'))");
        DB::statement("ALTER TABLE anomalies ADD CONSTRAINT chk_anomalies_severity
            CHECK (severity IN ('".implode("','", Severity::values())."'))");
        DB::statement("ALTER TABLE anomalies ADD CONSTRAINT chk_anomalies_status
            CHECK (status IN ('open','acknowledged','resolved','false_positive'))");
        DB::statement("ALTER TABLE anomalies ADD CONSTRAINT chk_anomalies_method
            CHECK (detection_method IN ('zscore','mad','iqr','isolation_forest','rule'))");
        DB::statement("CREATE INDEX idx_anomalies_project_open ON anomalies(project_id, detected_at DESC) WHERE status = 'open'");
    }

    public function down(): void
    {
        Schema::dropIfExists('anomalies');
    }
};
