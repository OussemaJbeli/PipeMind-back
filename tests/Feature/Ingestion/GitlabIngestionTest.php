<?php

declare(strict_types=1);

use App\Models\CommitChange;
use App\Models\Failure;
use App\Models\FailureSignature;
use App\Models\Integration;
use App\Models\JobLog;
use App\Models\Pipeline;
use App\Models\PipelineEvent;
use App\Models\PipelineJob;
use App\Models\Project;
use App\Models\Team;
use App\Services\Failures\FailureDetectionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\AiFakes;

beforeEach(function () {
    Storage::fake('logs');

    // Trailing * matters: Http::fake matches the FULL url including the query
    // string, and the diff request carries ?per_page=100.
    Http::fake([
        '*/jobs/*/trace*' => Http::response(
            file_get_contents(base_path('tests/fixtures/logs/failed-db-test.log')),
        ),
        '*/repository/commits/*/diff*' => Http::response([
            [
                'new_path' => 'docker-compose.yml', 'old_path' => 'docker-compose.yml',
                'new_file' => false, 'deleted_file' => false, 'renamed_file' => false,
                'diff' => "@@\n-    depends_on:\n-      - db\n+    depends_on: [db]\n",
            ],
            [
                'new_path' => 'app/Services/PaymentService.php', 'old_path' => 'app/Services/PaymentService.php',
                'new_file' => false, 'deleted_file' => false, 'renamed_file' => false,
                'diff' => "@@\n+public function charge()\n",
            ],
        ]),
        // The AI service sits behind one base URL, so its endpoints are routed
        // by path rather than by separate fakes.
        '*' => AiFakes::router(),
    ]);
});

function ingest(array $overrides = [], array $projectAttributes = []): Pipeline
{
    $integration = Integration::factory()->gitlab()->create();
    $project = Project::factory()->for($integration)->create([
        'team_id' => $integration->team_id,
        'external_id' => '42',
        'default_branch' => 'main',
        ...$projectAttributes,
    ]);

    $payload = array_replace_recursive(
        json_decode(file_get_contents(base_path('tests/fixtures/gitlab/pipeline-failed.json')), true),
        $overrides,
    );

    // The fixture carries fixed timestamps. Anchoring them to now keeps
    // "today" assertions (failures_today, daily rollups) from silently
    // becoming wrong as the fixture ages.
    $payload['object_attributes']['created_at'] ??= null;
    $payload['object_attributes']['created_at'] = now()->subMinutes(3)->toDateTimeString();
    $payload['object_attributes']['started_at'] = now()->subMinutes(3)->toDateTimeString();
    $payload['object_attributes']['finished_at'] = now()->toDateTimeString();

    test()->postJson("/api/webhooks/gitlab/{$integration->uuid}", $payload, [
        'X-Gitlab-Token' => $integration->webhook_secret,
    ])->assertStatus(202);

    return Pipeline::withoutGlobalScopes()->where('project_id', $project->id)->firstOrFail();
}

it('ingests a failed pipeline end to end', function () {
    $pipeline = ingest();

    expect($pipeline->status->value)->toBe('failed')
        ->and($pipeline->iid)->toBe(821)
        ->and($pipeline->ref)->toBe('feature/payment')
        ->and($pipeline->commit_short_sha)->toBe('a82c91f3')
        ->and($pipeline->duration_seconds)->toBe(134)
        ->and($pipeline->has_failure)->toBeTrue();

    expect($pipeline->jobs()->count())->toBe(4)
        ->and($pipeline->jobs_failed)->toBe(1)
        ->and($pipeline->jobs_succeeded)->toBe(2);

    // Stages roll up to their worst job, not their most common one.
    // value() returns the cast enum, not the raw string.
    expect($pipeline->stages()->where('name', 'test')->value('status')->value)->toBe('failed')
        ->and($pipeline->stages()->where('name', 'install')->value('status')->value)->toBe('success');
});

it('marks config and dependency changes', function () {
    $pipeline = ingest();

    $config = CommitChange::withoutGlobalScopes()
        ->where('pipeline_id', $pipeline->id)
        ->where('file_path', 'docker-compose.yml')
        ->first();

    // "The pipeline broke and docker-compose.yml changed" is most of the diagnosis.
    expect($config)->not->toBeNull()
        ->and($config->is_config)->toBeTrue()
        ->and($config->is_dependency)->toBeFalse();

    $source = CommitChange::withoutGlobalScopes()
        ->where('pipeline_id', $pipeline->id)
        ->where('file_path', 'app/Services/PaymentService.php')
        ->first();

    expect($source->is_config)->toBeFalse()
        ->and($source->language)->toBe('PHP');
});

it('stores the log and extracts the error', function () {
    $pipeline = ingest();

    $log = JobLog::withoutGlobalScopes()->where('pipeline_id', $pipeline->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->size_bytes)->toBeGreaterThan(0)
        ->and($log->checksum_sha256)->toHaveLength(64);

    // Only the failed job's log is fetched; green-job logs are storage cost.
    expect(JobLog::withoutGlobalScopes()->count())->toBe(1);

    Storage::disk('logs')->assertExists($log->storage_path);

    // The excerpt must contain the actual error, not just the tail.
    expect($log->excerpt)->toContain('SQLSTATE[HY000] [2002] Connection refused')
        ->and($log->processed_at)->not->toBeNull();
});

it('creates a failure with a signature', function () {
    $pipeline = ingest();

    $failure = Failure::withoutGlobalScopes()->where('pipeline_id', $pipeline->id)->first();

    expect($failure)->not->toBeNull()
        ->and($failure->job_name)->toBe('backend-tests')
        ->and($failure->stage_name)->toBe('test')
        ->and($failure->error_message)->toContain('SQLSTATE')
        ->and($failure->signature_id)->not->toBeNull()
        ->and($failure->occurrence_index)->toBe(1);
});

// Auto-analysis is switched off in these two: the analysis is the authority on
// severity and overwrites whatever detection chose, so with it running these
// would assert the AI service's answer rather than the ingest-time rule.
it('assigns medium severity on a feature branch', function () {
    $pipeline = ingest(projectAttributes: ['auto_analyze' => false]);

    $failure = Failure::withoutGlobalScopes()->where('pipeline_id', $pipeline->id)->first();

    expect($failure->severity->value)->toBe('medium');
});

it('escalates severity on the default branch', function () {
    $pipeline = ingest(
        ['object_attributes' => ['ref' => 'main']],
        projectAttributes: ['auto_analyze' => false],
    );

    $failure = Failure::withoutGlobalScopes()->where('pipeline_id', $pipeline->id)->first();

    expect($failure->severity->value)->toBe('high');
});

it('runs the analysis and lets it supersede the detection-time category', function () {
    $pipeline = ingest();

    $failure = Failure::withoutGlobalScopes()->where('pipeline_id', $pipeline->id)->first();
    $analysis = $failure->analyses()->first();

    expect($analysis)->not->toBeNull()
        ->and($analysis->status->value)->toBe('completed')
        ->and($analysis->root_cause)->toContain('database service')
        ->and($failure->fresh()->status->value)->toBe('analyzed')
        // The fake returns high regardless of branch; the point is that the
        // analysis wrote it, not detection.
        ->and($failure->fresh()->severity->value)->toBe('high');

    expect($analysis->evidence()->count())->toBe(1)
        ->and($analysis->recommendations()->count())->toBe(1);

    // Risk comes from action_type in the AI service, never from the model.
    expect($analysis->recommendations()->first()->risk->value ?? $analysis->recommendations()->first()->risk)
        ->toBe('high');
});

it('does not create a failure for a successful pipeline', function () {
    $integration = Integration::factory()->gitlab()->create();
    Project::factory()->for($integration)->create([
        'team_id' => $integration->team_id, 'external_id' => '42',
    ]);

    $payload = json_decode(file_get_contents(base_path('tests/fixtures/gitlab/pipeline-success.json')), true);

    $this->postJson("/api/webhooks/gitlab/{$integration->uuid}", $payload, [
        'X-Gitlab-Token' => $integration->webhook_secret,
    ])->assertStatus(202);

    expect(Failure::withoutGlobalScopes()->count())->toBe(0)
        ->and(JobLog::withoutGlobalScopes()->count())->toBe(0);
});

it('records a timeout distinctly from a plain failure', function () {
    $integration = Integration::factory()->gitlab()->create();
    Project::factory()->for($integration)->create([
        'team_id' => $integration->team_id, 'external_id' => '42',
    ]);

    $payload = json_decode(file_get_contents(base_path('tests/fixtures/gitlab/pipeline-timeout.json')), true);

    $this->postJson("/api/webhooks/gitlab/{$integration->uuid}", $payload, [
        'X-Gitlab-Token' => $integration->webhook_secret,
    ])->assertStatus(202);

    $job = Pipeline::withoutGlobalScopes()->latest('id')->first()
        ->jobs()->where('name', 'backend-tests')->first();

    expect($job->status->value)->toBe('timeout');
});

it('is idempotent when the same event is processed twice', function () {
    $integration = Integration::factory()->gitlab()->create();
    Project::factory()->for($integration)->create([
        'team_id' => $integration->team_id, 'external_id' => '42',
    ]);

    $payload = json_decode(file_get_contents(base_path('tests/fixtures/gitlab/pipeline-failed.json')), true);

    foreach (['first', 'second'] as $delivery) {
        $this->postJson("/api/webhooks/gitlab/{$integration->uuid}", $payload, [
            'X-Gitlab-Token' => $integration->webhook_secret,
            'X-Gitlab-Event-UUID' => $delivery,
        ])->assertStatus(202);
    }

    // Two distinct deliveries of the same pipeline converge to one row each.
    expect(Pipeline::withoutGlobalScopes()->count())->toBe(1)
        ->and(Pipeline::withoutGlobalScopes()->first()->jobs()->count())->toBe(4)
        ->and(Failure::withoutGlobalScopes()->count())->toBe(1);
});

it('skips a payload for a project it does not monitor', function () {
    $integration = Integration::factory()->gitlab()->create();
    Project::factory()->for($integration)->create([
        'team_id' => $integration->team_id, 'external_id' => '999',
    ]);

    $payload = json_decode(file_get_contents(base_path('tests/fixtures/gitlab/pipeline-failed.json')), true);

    $this->postJson("/api/webhooks/gitlab/{$integration->uuid}", $payload, [
        'X-Gitlab-Token' => $integration->webhook_secret,
    ])->assertStatus(202);

    expect(Pipeline::withoutGlobalScopes()->count())->toBe(0);

    // Not an error: recorded as skipped so it is visible without being noisy.
    expect(PipelineEvent::withoutGlobalScopes()->first()->processing_status)->toBe('skipped');
});

it('refreshes the project counters after ingestion', function () {
    $pipeline = ingest();
    $project = $pipeline->project->fresh();

    expect($project->pipelines_count)->toBe(1)
        ->and($project->failures_today)->toBe(1)
        ->and($project->health_status)->toBe('failing')
        ->and($project->last_pipeline_id)->toBe($pipeline->id);
});

it('upgrades a signature that was first seen as unknown', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $pipeline = Pipeline::factory()->failed()->for($project)->create();
    $job = PipelineJob::factory()->failed()->for($pipeline)->create();

    $signature = FailureSignature::factory()->create([
        'team_id' => $team->id,
        'category' => 'UNKNOWN',
        'subcategory' => null,
    ]);

    withTeam($team, function () use ($pipeline, $job, $signature) {
        app(FailureDetectionService::class)->detect($pipeline, $job, [
            'error_message' => 'SQLSTATE[HY000] [2002] Connection refused',
            'signature_hash' => $signature->hash,
            'category' => 'DATABASE',
            'subcategory' => 'ConnectionRefused',
        ]);
    });

    // Its category powers the failure-history catalogue and global search, so a
    // signature that predates the classifier must not stay UNKNOWN forever.
    expect($signature->fresh()->category->value)->toBe('DATABASE')
        ->and($signature->fresh()->subcategory)->toBe('ConnectionRefused');
});

it('never downgrades a classified signature back to unknown', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $pipeline = Pipeline::factory()->failed()->for($project)->create();
    $job = PipelineJob::factory()->failed()->for($pipeline)->create();

    $signature = FailureSignature::factory()->create([
        'team_id' => $team->id,
        'category' => 'DATABASE',
        'subcategory' => 'ConnectionRefused',
    ]);

    withTeam($team, function () use ($pipeline, $job, $signature) {
        app(FailureDetectionService::class)->detect($pipeline, $job, [
            'error_message' => 'something unhelpful',
            'signature_hash' => $signature->hash,
            'category' => 'UNKNOWN',
        ]);
    });

    // A later low-signal occurrence must not erase a good label.
    expect($signature->fresh()->category->value)->toBe('DATABASE');
});
