<?php

declare(strict_types=1);

use App\Enums\RemediationStatus;
use App\Exceptions\Integrations\IntegrationUnauthorized;
use App\Exceptions\Remediation\PatchDoesNotApply;
use App\Jobs\ExecuteRemediation;
use App\Jobs\WatchRemediationOutcome;
use App\Models\Failure;
use App\Models\Integration;
use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Remediation;
use App\Models\RemediationPolicy;
use App\Models\Team;
use App\Models\User;
use App\Services\Remediation\RemediationExecutorRegistry;
use App\Services\Remediation\RemediationPolicyEvaluator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** @return array{0: Remediation, 1: Project} */
function executable(
    array $overrides = [],
    array $recommendation = [],
    string $status = 'approved',
    string $pipelineStatus = 'failed',
): array {
    $team = Team::factory()->create();
    $integration = Integration::factory()->create(['team_id' => $team->id, 'provider' => 'github']);
    $project = Project::factory()->create([
        'team_id' => $team->id,
        'integration_id' => $integration->id,
        'external_path' => 'acme/app',
        'default_branch' => 'main',
    ]);
    $pipeline = Pipeline::factory()->create([
        'project_id' => $project->id,
        'ref' => 'feature/x',
        'status' => $pipelineStatus,
        'external_id' => '900',
        'commit_sha' => str_repeat('a', 40),
    ]);
    $job = PipelineJob::factory()->create([
        'pipeline_id' => $pipeline->id,
        'name' => 'backend-tests',
        'external_id' => '5001',
    ]);
    $failure = Failure::factory()->create([
        'team_id' => $team->id, 'project_id' => $project->id,
        'pipeline_id' => $pipeline->id, 'job_id' => $job->id,
    ]);

    RemediationPolicy::factory()->create([
        'team_id' => $team->id,
        'action_type' => $overrides['action_type'] ?? 'retry_job',
        'mode' => 'auto', 'max_risk' => 'critical', 'min_confidence' => 0.0,
        'blocked_branches' => [], 'max_per_day' => 50,
    ]);

    $rec = Recommendation::factory()->create([
        'failure_id' => $failure->id,
        ...['action_type' => $overrides['action_type'] ?? 'retry_job',
            'confidence' => 0.95, ...$recommendation],
    ]);

    $remediation = Remediation::factory()->create([
        'team_id' => $team->id, 'project_id' => $project->id,
        'failure_id' => $failure->id, 'recommendation_id' => $rec->id,
        'status' => $status, 'policy_decision' => 'auto_allowed',
        'approved_at' => now(), 'expires_at' => now()->addDay(),
        ...$overrides,
    ]);

    return [$remediation, $project];
}

it('retries the job and records the result', function () {
    Http::fake(['*/actions/jobs/5001/rerun' => Http::response(['id' => 77, 'html_url' => 'https://gh/run/77'], 201)]);
    [$remediation] = executable();

    (new ExecuteRemediation($remediation->id))->handle(
        app(RemediationExecutorRegistry::class),
        app(RemediationPolicyEvaluator::class),
    );

    $fresh = $remediation->fresh();

    expect($fresh->status)->toBe(RemediationStatus::SUCCEEDED)
        ->and($fresh->result['url'])->toBe('https://gh/run/77')
        ->and($fresh->executed_at)->not->toBeNull()
        // Every mutating action produces something on the provider; a
        // remediation you cannot click through to is one taken on trust.
        ->and($fresh->result['summary'])->toContain('backend-tests');

    expect(collect($fresh->audit)->pluck('event')->all())->toContain('executing', 'succeeded');
});

it('refuses to execute once the approval has expired', function () {
    Http::preventStrayRequests();
    [$remediation] = executable(['expires_at' => now()->subMinute()]);

    (new ExecuteRemediation($remediation->id))->handle(
        app(RemediationExecutorRegistry::class),
        app(RemediationPolicyEvaluator::class),
    );

    // Http::preventStrayRequests is the real assertion: nothing was called.
    expect($remediation->fresh()->status)->toBe(RemediationStatus::EXPIRED)
        ->and($remediation->fresh()->error)->toContain('expired');
});

it('cancels rather than executing when the policy has been tightened since approval', function () {
    Http::preventStrayRequests();
    [$remediation] = executable();

    // The hazard: approval granted an hour ago, policy changed since. The row
    // still says "approved", so only a re-check catches it.
    RemediationPolicy::where('team_id', $remediation->team_id)
        ->where('action_type', 'retry_job')
        ->update(['mode' => 'forbidden']);

    (new ExecuteRemediation($remediation->id))->handle(
        app(RemediationExecutorRegistry::class),
        app(RemediationPolicyEvaluator::class),
    );

    expect($remediation->fresh()->status)->toBe(RemediationStatus::CANCELLED)
        ->and($remediation->fresh()->error)->toContain('no longer permits');
});

it('cancels when a human has already retried the pipeline', function () {
    Http::preventStrayRequests();
    // Someone hit retry themselves in the window between approval and
    // execution. Doing it again is duplicate work, not remediation.
    [$remediation] = executable(pipelineStatus: 'running');

    (new ExecuteRemediation($remediation->id))->handle(
        app(RemediationExecutorRegistry::class),
        app(RemediationPolicyEvaluator::class),
    );

    expect($remediation->fresh()->status)->toBe(RemediationStatus::CANCELLED)
        ->and($remediation->fresh()->error)->toContain('someone retried it first');
});

it('will not execute a remediation that is still pending approval', function () {
    Http::preventStrayRequests();
    [$remediation] = executable(status: 'pending_approval');

    (new ExecuteRemediation($remediation->id))->handle(
        app(RemediationExecutorRegistry::class),
        app(RemediationPolicyEvaluator::class),
    );

    expect($remediation->fresh()->status)->toBe(RemediationStatus::PENDING_APPROVAL);
});

it('records a failure with the provider\'s reason instead of a generic error', function () {
    Http::fake(['*/actions/jobs/5001/rerun' => Http::response(['message' => 'Resource not accessible by integration'], 403)]);
    [$remediation] = executable();

    expect(fn () => (new ExecuteRemediation($remediation->id))->handle(
        app(RemediationExecutorRegistry::class),
        app(RemediationPolicyEvaluator::class),
    ))->toThrow(IntegrationUnauthorized::class);

    // A read-only token is the single most likely failure here, so the message
    // has to name it rather than say "the action failed" — or, as it did before
    // this test existed, blame a rate limit that was never hit.
    expect($remediation->fresh()->status)->toBe(RemediationStatus::FAILED)
        ->and($remediation->fresh()->error)->toContain('not accessible');
});

it('opens a merge request without ever committing to the default branch', function () {
    $captured = [];

    Http::fake(function ($request) use (&$captured) {
        $captured[] = ['method' => $request->method(), 'url' => $request->url(), 'body' => $request->data()];

        return match (true) {
            str_contains($request->url(), '/contents/docker-compose.yml') && $request->method() === 'GET' => Http::response("services:\n  postgres:\n    image: postgres:16\n", 200),
            str_contains($request->url(), '/git/refs') => Http::response(['ref' => 'refs/heads/x'], 201),
            str_contains($request->url(), '/contents/') => Http::response(['commit' => ['sha' => 'b']], 200),
            str_contains($request->url(), '/pulls') => Http::response(['number' => 42, 'html_url' => 'https://gh/pull/42'], 201),
            default => Http::response([], 200),
        };
    });

    [$remediation] = executable(
        ['action_type' => 'create_merge_request'],
        ['action_type' => 'create_merge_request',
            'affected_files' => ['docker-compose.yml'],
            'patch' => "--- a/docker-compose.yml\n+++ b/docker-compose.yml\n@@ -1,3 +1,4 @@\n services:\n   postgres:\n     image: postgres:16\n+    healthcheck: {test: pg_isready}\n"],
    );

    (new ExecuteRemediation($remediation->id))->handle(
        app(RemediationExecutorRegistry::class),
        app(RemediationPolicyEvaluator::class),
    );

    $fresh = $remediation->fresh();

    expect($fresh->status)->toBe(RemediationStatus::SUCCEEDED)
        ->and($fresh->result['url'])->toBe('https://gh/pull/42');

    $branchCall = collect($captured)->firstWhere(fn ($c) => str_contains($c['url'], '/git/refs'));
    $commitCall = collect($captured)->first(fn ($c) => $c['method'] === 'PUT');
    $prCall = collect($captured)->firstWhere(fn ($c) => str_contains($c['url'], '/pulls'));

    // The guarantee that matters: a new branch is created, the commit targets
    // that branch, and the change is *proposed* to the failure's branch.
    expect($branchCall['body']['ref'])->toStartWith('refs/heads/pipemind/')
        ->and($commitCall['body']['branch'])->toStartWith('pipemind/')
        ->and($commitCall['body']['branch'])->not->toBe('main')
        ->and($prCall['body']['base'])->toBe('feature/x')
        ->and($prCall['body']['head'])->toStartWith('pipemind/');

    // And the committed content is the patch applied, not the diff itself.
    expect(base64_decode($commitCall['body']['content']))->toContain('healthcheck: {test: pg_isready}');
});

it('refuses a patch that reaches a file the analysis never mentioned', function () {
    Http::fake(['*' => Http::response([], 200)]);

    // Defence against the model, not the user. Mirrors validate_patch() in the
    // AI service: a diff touching an undeclared path is rejected structurally.
    [$remediation] = executable(
        ['action_type' => 'create_merge_request'],
        ['action_type' => 'create_merge_request',
            'affected_files' => ['docker-compose.yml'],
            'patch' => "--- a/.github/workflows/deploy.yml\n+++ b/.github/workflows/deploy.yml\n@@ -1,1 +1,1 @@\n-on: push\n+on: [push, workflow_dispatch]\n"],
    );

    expect(fn () => (new ExecuteRemediation($remediation->id))->handle(
        app(RemediationExecutorRegistry::class),
        app(RemediationPolicyEvaluator::class),
    ))->toThrow(PatchDoesNotApply::class);

    expect($remediation->fresh()->error)->toContain('never mentioned');
    Http::assertNothingSent();
});

it('refuses when the file has changed since the patch was written', function () {
    Http::fake([
        '*/contents/docker-compose.yml*' => Http::response("services:\n  postgres:\n    image: postgres:15\n", 200),
        '*' => Http::response([], 200),
    ]);

    [$remediation] = executable(
        ['action_type' => 'create_merge_request'],
        ['action_type' => 'create_merge_request',
            'affected_files' => ['docker-compose.yml'],
            'patch' => "--- a/docker-compose.yml\n+++ b/docker-compose.yml\n@@ -1,3 +1,4 @@\n services:\n   postgres:\n     image: postgres:16\n+    healthcheck: {test: pg_isready}\n"],
    );

    expect(fn () => (new ExecuteRemediation($remediation->id))->handle(
        app(RemediationExecutorRegistry::class),
        app(RemediationPolicyEvaluator::class),
    ))->toThrow(PatchDoesNotApply::class);

    // Applying a near-miss would commit code nobody wrote or reviewed.
    expect($remediation->fresh()->error)->toContain('does not match');
});

it('describes a dry run without calling the provider at all', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    [$remediation, $project] = executable();

    $user->teams()->attach($project->team_id, ['role' => 'admin', 'joined_at' => now()]);
    $user->forceFill(['current_team_id' => $project->team_id])->save();

    $data = $this->actingAs($user)
        ->getJson("/api/v1/remediations/{$remediation->uuid}/dry-run")
        ->assertOk()->json('data');

    // The first thing anyone sensibly asks of a tool that edits their
    // repository is to watch it do nothing.
    expect($data['possible'])->toBeTrue()
        ->and($data['summary'])->toContain('Would re-run')
        ->and($data['dry_run'])->toBeTrue();
});

it('watches for the outcome only when there is something to watch', function () {
    Queue::fake();
    Http::fake(['*/actions/jobs/5001/rerun' => Http::response(['id' => 77], 201)]);
    [$remediation] = executable();

    (new ExecuteRemediation($remediation->id))->handle(
        app(RemediationExecutorRegistry::class),
        app(RemediationPolicyEvaluator::class),
    );

    // "Succeeded" only means the API accepted the request. Whether it fixed
    // anything is a separate fact that arrives minutes later.
    Queue::assertPushed(WatchRemediationOutcome::class);
});
