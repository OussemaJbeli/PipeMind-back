<?php

declare(strict_types=1);

use App\Integrations\ProviderRegistry;
use App\Jobs\WatchRemediationOutcome;
use App\Models\Failure;
use App\Models\FailureSignature;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Remediation;
use App\Models\Team;
use Illuminate\Support\Facades\Queue;

/** @return array{0: Remediation, 1: Pipeline, 2: FailureSignature} */
function watched(string $signatureState = 'unknown'): array
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);

    $signature = FailureSignature::factory()->create([
        'team_id' => $team->id,
        'is_known' => $signatureState === 'known',
        'known_resolution' => $signatureState === 'known' ? 'A human wrote this one.' : null,
        'resolution_confirmed_at' => $signatureState === 'known' ? now()->subDay() : null,
    ]);

    $original = Pipeline::factory()->create([
        'project_id' => $project->id, 'ref' => 'feature/x',
        'commit_sha' => str_repeat('c', 40), 'status' => 'failed',
    ]);

    $failure = Failure::factory()->create([
        'team_id' => $team->id, 'project_id' => $project->id,
        'pipeline_id' => $original->id, 'signature_id' => $signature->id,
    ]);

    $rec = Recommendation::factory()->create([
        'failure_id' => $failure->id,
        'action_type' => 'retry_job',
        'title' => 'Wait for the database healthcheck before running tests',
    ]);

    $remediation = Remediation::factory()->succeeded()->create([
        'team_id' => $team->id, 'project_id' => $project->id,
        'failure_id' => $failure->id, 'recommendation_id' => $rec->id,
        'executed_at' => now()->subMinutes(3),
        'outcome_success' => null,
    ]);

    return [$remediation, $original, $signature];
}

function retryPipeline(Remediation $remediation, Pipeline $original, string $status): Pipeline
{
    // A retry produces a new pipeline on the same branch and commit, created
    // after the remediation ran. That is what identifies it.
    return Pipeline::factory()->create([
        'project_id' => $remediation->project_id,
        'ref' => $original->ref,
        'commit_sha' => $original->commit_sha,
        'status' => $status,
        'created_at' => now(),
    ]);
}

function watch(Remediation $remediation, int $attempt = 1): void
{
    (new WatchRemediationOutcome($remediation->id, $attempt))->handle(app(ProviderRegistry::class));
}

it('promotes the signature to known when the retried pipeline goes green', function () {
    Queue::fake();
    [$remediation, $original, $signature] = watched();
    $retry = retryPipeline($remediation, $original, 'success');

    watch($remediation);

    // This is the loop closing. A retry that passes is evidence the fix works,
    // and a known signature short-circuits every future occurrence with no
    // model call at all — the cheapest and most trustworthy path there is.
    expect($remediation->fresh()->outcome_success)->toBeTrue()
        ->and($remediation->fresh()->resulting_pipeline_id)->toBe($retry->id);

    $fresh = $signature->fresh();

    expect($fresh->is_known)->toBeTrue()
        ->and($fresh->known_resolution)->toBe('Wait for the database healthcheck before running tests')
        // Nobody typed this, so it must not claim a human vouched for it.
        ->and($fresh->resolution_confirmed_by)->toBeNull()
        ->and($fresh->resolution_confirmed_at)->not->toBeNull();
});

it('records that the remediation did not fix it when the retry fails again', function () {
    Queue::fake();
    [$remediation, $original, $signature] = watched();
    retryPipeline($remediation, $original, 'failed');

    watch($remediation);

    expect($remediation->fresh()->outcome_success)->toBeFalse()
        ->and($signature->fresh()->is_known)->toBeFalse();

    $this->assertDatabaseHas('activity_logs', ['action' => 'remediation.did_not_fix']);
});

it('never overwrites a resolution a human confirmed', function () {
    Queue::fake();
    [$remediation, $original, $signature] = watched('known');
    retryPipeline($remediation, $original, 'success');

    watch($remediation);

    // A person's wording is better than ours, and replacing it silently would
    // be the system overruling the expert it learned from.
    expect($signature->fresh()->known_resolution)->toBe('A human wrote this one.')
        ->and($remediation->fresh()->outcome_success)->toBeTrue();
});

it('polls again while the retried pipeline is still running', function () {
    Queue::fake();
    [$remediation, $original] = watched();
    retryPipeline($remediation, $original, 'running');

    watch($remediation);

    // outcome_success stays null: "not verified yet" is not "did not fix".
    expect($remediation->fresh()->outcome_success)->toBeNull();
    Queue::assertPushed(WatchRemediationOutcome::class);
});

it('polls again when no retry pipeline has appeared yet', function () {
    Queue::fake();
    [$remediation] = watched();

    watch($remediation);

    expect($remediation->fresh()->outcome_success)->toBeNull();
    Queue::assertPushed(WatchRemediationOutcome::class);
});

it('gives up after the attempt limit and says so, rather than polling forever', function () {
    Queue::fake();
    [$remediation] = watched();

    watch($remediation, attempt: 10);

    expect($remediation->fresh()->outcome_success)->toBeNull()
        ->and(collect($remediation->fresh()->audit)->pluck('event')->all())
        ->toContain('outcome_unknown');

    Queue::assertNotPushed(WatchRemediationOutcome::class);
});

it('ignores the original failed pipeline when looking for the retry', function () {
    Queue::fake();
    [$remediation, $original] = watched();

    // Without whereKeyNot, the original failed run matches on branch and commit
    // and the remediation reports itself as having failed immediately.
    watch($remediation);

    expect($remediation->fresh()->resulting_pipeline_id)->toBeNull()
        ->and($remediation->fresh()->outcome_success)->toBeNull();
});

it('does nothing for a remediation that did not succeed', function () {
    Queue::fake();
    [$remediation, $original] = watched();
    $remediation->update(['status' => 'failed']);
    retryPipeline($remediation, $original, 'success');

    watch($remediation);

    expect($remediation->fresh()->outcome_success)->toBeNull();
});
