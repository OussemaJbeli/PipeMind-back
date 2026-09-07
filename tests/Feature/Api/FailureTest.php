<?php

declare(strict_types=1);

use App\Jobs\AnalyzeFailure;
use App\Models\Analysis;
use App\Models\AnalysisEvidence;
use App\Models\AnalysisFeedback;
use App\Models\CommitChange;
use App\Models\Failure;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function failureFor(User $user, array $attributes = []): Failure
{
    $project = Project::factory()->create(['team_id' => $user->current_team_id]);
    // pipelines and pipeline_jobs carry no team_id: they are scoped through the
    // project, so a team column here would be a second source of truth.
    $pipeline = Pipeline::factory()->failed()->for($project)->create(['ref' => 'feature/payment']);
    $job = PipelineJob::factory()->failed()->for($pipeline)->create();

    return Failure::factory()->create([
        'team_id' => $user->current_team_id,
        'project_id' => $project->id,
        'pipeline_id' => $pipeline->id,
        'job_id' => $job->id,
        ...$attributes,
    ]);
}

it('lists failures newest first', function () {
    $user = User::factory()->withTeam()->create();

    failureFor($user, ['failed_at' => now()->subDays(2), 'error_message' => 'older']);
    failureFor($user, ['failed_at' => now(), 'error_message' => 'newer']);

    $response = $this->actingAs($user)->getJson('/api/v1/failures')->assertOk();

    expect($response->json('data.0.error_message'))->toBe('newer')
        ->and($response->json('meta.total'))->toBe(2);
});

it('filters failures by category and resolution', function () {
    $user = User::factory()->withTeam()->create();

    failureFor($user, ['category' => 'DATABASE']);
    failureFor($user, ['category' => 'NETWORK', 'resolved_at' => now()]);

    expect($this->actingAs($user)->getJson('/api/v1/failures?category=DATABASE')->json('data'))
        ->toHaveCount(1);

    expect($this->actingAs($user)->getJson('/api/v1/failures?resolved=1')->json('data'))
        ->toHaveCount(1);
});

it('separates observed facts from the analysis', function () {
    $user = User::factory()->withTeam()->create();
    $failure = failureFor($user);

    CommitChange::factory()->create([
        'pipeline_id' => $failure->pipeline_id,
        'project_id' => $failure->project_id,
        'file_path' => 'docker-compose.yml',
        'is_config' => true,
    ]);

    $analysis = Analysis::factory()->create([
        'failure_id' => $failure->id,
        'team_id' => $failure->team_id,
        'status' => 'completed',
        'confidence' => 0.92,
        'root_cause' => 'The database container was not ready.',
    ]);

    AnalysisEvidence::factory()->create([
        'analysis_id' => $analysis->id,
        'type' => 'log_line',
        'source_ref' => 'job_logs#L1294',
    ]);

    Recommendation::factory()->create([
        'analysis_id' => $analysis->id,
        'failure_id' => $failure->id,
    ]);

    $data = $this->actingAs($user)->getJson("/api/v1/failures/{$failure->uuid}")
        ->assertOk()
        ->json('data');

    // The trust model depends on this split: a fact and an inference must never
    // share a shape, or the UI cannot honestly distinguish them.
    expect($data['observed']['changed_files'])->toHaveCount(1)
        ->and($data['observed']['changed_files'][0]['is_config'])->toBeTrue()
        ->and($data['observed'])->not->toHaveKey('root_cause');

    expect($data['analysis']['root_cause'])->toBe('The database container was not ready.')
        ->and($data['analysis']['confidence'])->toBe(0.92)
        // Provenance travels with every inference.
        ->and($data['analysis'])->toHaveKeys([
            'classification_source', 'classification_confidence', 'used_rag',
            'model_provider', 'model_name', 'cost_usd', 'cache_hit',
        ])
        ->and($data['analysis']['evidence'][0]['source_ref'])->toBe('job_logs#L1294');

    expect($data['recommendations'])->toHaveCount(1);
});

it('reports the previous pipeline on the same branch', function () {
    $user = User::factory()->withTeam()->create();
    $failure = failureFor($user);

    Pipeline::factory()->for($failure->project)->create([
        'ref' => 'feature/payment',
        'status' => 'success',
        'id' => $failure->pipeline_id - 1,
    ]);

    $data = $this->actingAs($user)->getJson("/api/v1/failures/{$failure->uuid}")->json('data');

    // Green until this commit is the highest-signal fact the page can show.
    expect($data['observed']['previous_pipeline']['status'])->toBe('success');
});

it('queues an analysis', function () {
    Queue::fake();
    $user = User::factory()->withTeam()->create();
    $failure = failureFor($user, ['status' => 'detected']);

    $this->actingAs($user)->postJson("/api/v1/failures/{$failure->uuid}/analyze")
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued');

    Queue::assertPushed(AnalyzeFailure::class, fn ($job) => $job->failureId === $failure->id
        && $job->force === false);
});

it('passes force through so a re-analysis bypasses the cache', function () {
    Queue::fake();
    $user = User::factory()->withTeam()->create();
    $failure = failureFor($user);

    $this->actingAs($user)->postJson("/api/v1/failures/{$failure->uuid}/analyze?force=1")
        ->assertStatus(202);

    Queue::assertPushed(AnalyzeFailure::class, fn ($job) => $job->force === true);
});

it('refuses to queue a second analysis while one is running', function () {
    Queue::fake();
    $user = User::factory()->withTeam()->create();
    $failure = failureFor($user, ['status' => 'analyzing']);

    $this->actingAs($user)->postJson("/api/v1/failures/{$failure->uuid}/analyze")
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'ANALYSIS_IN_PROGRESS');

    Queue::assertNothingPushed();
});

it('resolves a failure and records how long it took', function () {
    $user = User::factory()->withTeam()->create();
    $failure = failureFor($user, ['failed_at' => now()->subMinutes(15)]);

    $this->actingAs($user)->putJson("/api/v1/failures/{$failure->uuid}/resolve", [
        'resolution_type' => 'fixed',
        'resolution_note' => 'Added a healthcheck to docker-compose.yml.',
    ])->assertOk();

    $failure->refresh();

    expect($failure->status->value)->toBe('resolved')
        ->and($failure->resolution_type)->toBe('fixed')
        ->and($failure->resolved_by)->toBe($user->id)
        ->and($failure->time_to_resolution_seconds)->toBeGreaterThan(800);
});

it('rejects a resolution type the database would refuse', function () {
    $user = User::factory()->withTeam()->create();
    $failure = failureFor($user);

    // 'unresolved' is in the CHECK constraint but is not a resolution.
    $this->actingAs($user)->putJson("/api/v1/failures/{$failure->uuid}/resolve", [
        'resolution_type' => 'unresolved',
    ])->assertStatus(422);
});

it('builds a timeline from the rows rather than a second log', function () {
    $user = User::factory()->withTeam()->create();
    $failure = failureFor($user, [
        'failed_at' => now()->subMinutes(20),
        'detected_at' => now()->subMinutes(19),
        'occurrence_index' => 4,
    ]);

    $analysis = Analysis::factory()->create([
        'failure_id' => $failure->id,
        'team_id' => $failure->team_id,
        'status' => 'completed',
        'confidence' => 0.92,
        'model_name' => 'gemini-3.6-flash',
        'cost_usd' => 0.0023,
        'completed_at' => now()->subMinutes(18),
    ]);

    AnalysisFeedback::create([
        'analysis_id' => $analysis->id,
        'user_id' => $user->id,
        'was_helpful' => true,
    ]);

    $failure->update([
        'status' => 'resolved',
        'resolved_at' => now()->subMinutes(2),
        'resolved_by' => $user->id,
        'resolution_type' => 'fixed',
        'resolution_note' => 'Restored the healthcheck',
    ]);

    $events = $this->actingAs($user)->getJson("/api/v1/failures/{$failure->uuid}/timeline")
        ->assertOk()->json('data');

    $types = array_column($events, 'type');

    expect($types)->toContain('failed', 'detected', 'analyzed', 'feedback_positive', 'resolved');

    // Ordered, and with no null timestamps sorted to 1970.
    $timestamps = array_column($events, 'at');
    $sorted = $timestamps;
    sort($sorted);
    expect($timestamps)->toBe($sorted)
        ->and($timestamps)->not->toContain(null);

    // Recurrence is stated: occurrence #4 is a different situation from a first
    // sighting, and the reader should not have to work that out.
    $detected = collect($events)->firstWhere('type', 'detected');
    expect($detected['detail'])->toContain('#4');
});

it('reports an analysis failure distinctly from a pipeline failure in the timeline', function () {
    $user = User::factory()->withTeam()->create();
    $failure = failureFor($user);

    Analysis::factory()->create([
        'failure_id' => $failure->id,
        'team_id' => $failure->team_id,
        'status' => 'failed',
        'error' => 'The analysis service is unreachable.',
        'completed_at' => now(),
    ]);

    $events = $this->actingAs($user)->getJson("/api/v1/failures/{$failure->uuid}/timeline")
        ->json('data');

    $entry = collect($events)->firstWhere('type', 'analysis_failed');

    expect($entry)->not->toBeNull()
        ->and($entry['detail'])->toContain('unreachable');
});

it('serves recommendations on their own with the patch inline', function () {
    $user = User::factory()->withTeam()->create();
    $failure = failureFor($user);

    $analysis = Analysis::factory()->create([
        'failure_id' => $failure->id, 'team_id' => $failure->team_id, 'status' => 'completed',
    ]);

    Recommendation::factory()->create([
        'analysis_id' => $analysis->id,
        'failure_id' => $failure->id,
        'patch' => "--- a/x.ts\n+++ b/x.ts\n@@ -1,1 +1,1 @@\n-old\n+new\n",
    ]);

    $data = $this->actingAs($user)->getJson("/api/v1/failures/{$failure->uuid}/recommendations")
        ->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['has_patch'])->toBeTrue()
        // Inline: it is already bounded to 8 KB, and a second round trip to read
        // a diff the user is looking at buys nothing.
        ->and($data[0]['patch'])->toContain('+new');
});

it('never serves another team\'s timeline', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();
    $failure = failureFor($theirs);

    $this->actingAs($mine)->getJson("/api/v1/failures/{$failure->uuid}/timeline")->assertNotFound();
    $this->actingAs($mine)->getJson("/api/v1/failures/{$failure->uuid}/recommendations")->assertNotFound();
});
