<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The policy gate, stored on the recommendation at persist time.
 *
 * Kept here rather than computed on read so the failure page can render
 * "Approve" versus "Not allowed" without evaluating a policy per recommendation
 * on every request. It is a cache of a decision, not the authority for one:
 * ExecuteRemediation re-evaluates before acting, because an approval granted an
 * hour ago must not execute under a policy that has since been tightened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table): void {
            // Nullable: recommendations persisted before the evaluator existed
            // have no decision, and the UI already treats null as "gate unknown"
            // rather than as permission.
            $table->string('policy_decision', 20)->nullable()->after('status');
            $table->string('policy_reason', 255)->nullable()->after('policy_decision');
        });

        // Constrained in the database, matching every other enum-like column in
        // this schema. A decision the API cannot produce should not be storable
        // by a bad backfill or a stray tinker session either.
        DB::statement("
            ALTER TABLE recommendations
            ADD CONSTRAINT recommendations_policy_decision_check
            CHECK (policy_decision IN ('auto_allowed','requires_approval','forbidden'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE recommendations DROP CONSTRAINT IF EXISTS recommendations_policy_decision_check');

        Schema::table('recommendations', function (Blueprint $table): void {
            $table->dropColumn(['policy_decision', 'policy_reason']);
        });
    }
};
