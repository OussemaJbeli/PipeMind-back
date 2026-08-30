<?php

use App\Enums\FailureCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The learning loop. correct_category is a human-verified label contributed by a
 * developer who just debugged the failure: free, perfectly in-domain training data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('was_helpful');
            $table->boolean('root_cause_correct')->nullable();
            $table->string('correct_category', 30)->nullable();
            $table->text('actual_root_cause')->nullable();
            $table->text('actual_resolution')->nullable();
            $table->text('comment')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['analysis_id', 'user_id']);
        });

        DB::statement("ALTER TABLE analysis_feedback ADD CONSTRAINT chk_feedback_category
            CHECK (correct_category IS NULL OR correct_category IN ('".implode("','", FailureCategory::values())."'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_feedback');
    }
};
