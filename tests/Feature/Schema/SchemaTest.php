<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has every expected domain table', function () {
    $expected = [
        'users', 'teams', 'team_user', 'team_invitations', 'integrations', 'projects',
        'pipelines', 'pipeline_stages', 'pipeline_jobs', 'pipeline_events', 'job_logs',
        'commit_changes', 'failure_signatures', 'failures', 'analyses', 'analysis_evidence',
        'analysis_feedback', 'recommendations', 'remediations', 'remediation_policies',
        'failure_embeddings', 'knowledge_documents', 'knowledge_chunks',
        'project_metrics_daily', 'job_baselines', 'anomalies', 'ai_providers',
        'ai_requests', 'notification_channels', 'notifications', 'activity_logs',
    ];

    foreach ($expected as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table: {$table}");
    }

    expect($expected)->toHaveCount(31);
});

it('has the pgvector columns and HNSW indexes', function () {
    $vectorCols = DB::selectOne(
        "select count(*) c from information_schema.columns where udt_name = 'vector'"
    )->c;

    $hnsw = DB::selectOne(
        "select count(*) c from pg_indexes where indexdef like '%hnsw%'"
    )->c;

    expect((int) $vectorCols)->toBe(2)
        ->and((int) $hnsw)->toBe(2);
});

it('enforces enum values with CHECK constraints', function () {
    $team = Team::factory()->create();

    expect(fn () => DB::table('failures')->insert([
        'team_id' => $team->id, 'project_id' => 1, 'pipeline_id' => 1,
        'category' => 'DATABASE', 'severity' => 'urgent', 'failed_at' => now(),
    ]))->toThrow(QueryException::class);
});
