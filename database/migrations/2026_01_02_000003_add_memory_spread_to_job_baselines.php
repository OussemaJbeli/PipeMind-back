<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memory needs the same robust spread that duration already has.
 *
 * With only `mean_memory_mb` stored, the memory detector had nothing to measure
 * deviation against and fell back to a hardcoded 1.5× ratio — which fired on 22
 * of 24 seeded jobs. A threshold that flags almost everything is not a detector,
 * it is noise with a severity label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_baselines', function (Blueprint $table): void {
            $table->decimal('median_memory_mb', 10, 2)->nullable()->after('mean_memory_mb');
            $table->decimal('mad_memory', 10, 2)->nullable()->after('median_memory_mb');
        });
    }

    public function down(): void
    {
        Schema::table('job_baselines', function (Blueprint $table): void {
            $table->dropColumn(['median_memory_mb', 'mad_memory']);
        });
    }
};
