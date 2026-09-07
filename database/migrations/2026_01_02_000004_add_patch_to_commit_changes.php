<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the diff hunk we were already being given.
 *
 * Both GitHub and GitLab return the unified diff per file in the same call we
 * already make, and we discarded it — storing only "+1/-1". The model was then
 * asked to explain a failure having never seen a line of the code that caused
 * it, which is why every recommendation read like "check the recent changes".
 *
 * Capped at ~40 KB per file on write: a vendored lockfile diff is megabytes and
 * carries no diagnostic value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commit_changes', function (Blueprint $table): void {
            $table->text('patch')->nullable()->after('deletions');
            // Set when the diff was too large to keep, so the UI can say
            // "truncated" rather than implying the file changed by nothing.
            $table->boolean('patch_truncated')->default(false)->after('patch');
        });
    }

    public function down(): void
    {
        Schema::table('commit_changes', function (Blueprint $table): void {
            $table->dropColumn(['patch', 'patch_truncated']);
        });
    }
};
