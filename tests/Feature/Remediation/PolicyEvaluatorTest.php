<?php

declare(strict_types=1);

use App\Models\Failure;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Remediation;
use App\Models\RemediationPolicy;
use App\Models\Team;
use App\Services\Remediation\PolicyDecision;
use App\Services\Remediation\RemediationPolicyEvaluator;

/**
 * @return array{0: Recommendation, 1: Failure}
 */
function gateFixture(Team $team, array $recommendation = [], string $ref = 'feature/x'): array
{
    $project = Project::factory()->create([
        'team_id' => $team->id,
        'default_branch' => 'main',
    ]);

    // No team_id: pipelines are scoped through their project (ScopedThroughProject).
    $pipeline = Pipeline::factory()->create([
        'project_id' => $project->id,
        'ref' => $ref,
    ]);

    $failure = Failure::factory()->create([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'pipeline_id' => $pipeline->id,
    ]);

    $rec = Recommendation::factory()->create([
        'failure_id' => $failure->id,
        ...['action_type' => 'retry_job', 'confidence' => 0.95, 'risk' => 'low', ...$recommendation],
    ]);

    return [$rec, $failure->fresh(['project', 'pipeline'])];
}

function decide(Recommendation $rec, Failure $failure): PolicyDecision
{
    return app(RemediationPolicyEvaluator::class)->evaluate($rec, $failure);
}

it('forbids an action with no policy at all, rather than defaulting to allow', function () {
    $team = Team::factory()->create();
    [$rec, $failure] = gateFixture($team);

    // Invariant 1. A gap in the policy table is not permission. This is the
    // difference between a gate and a suggestion.
    expect(decide($rec, $failure)->isForbidden())->toBeTrue();
});

it('forbids an action whose policy has been disabled', function () {
    $team = Team::factory()->create();
    RemediationPolicy::factory()->create([
        'team_id' => $team->id, 'action_type' => 'retry_job', 'enabled' => false,
    ]);
    [$rec, $failure] = gateFixture($team);

    expect(decide($rec, $failure)->isForbidden())->toBeTrue();
});

it('takes risk from the action type and ignores what the model claimed', function () {
    $team = Team::factory()->create();
    RemediationPolicy::factory()->anyBranch()->create([
        'team_id' => $team->id, 'action_type' => 'rollback_deployment',
        'mode' => 'auto', 'max_risk' => 'low', 'min_confidence' => 0.0,
    ]);

    // Invariant 2. The model says "low"; ActionType says CRITICAL. A model that
    // could relabel its own risk would be able to talk past the gate entirely.
    [$rec, $failure] = gateFixture($team, [
        'action_type' => 'rollback_deployment', 'risk' => 'low', 'confidence' => 1.0,
    ]);

    $decision = decide($rec, $failure);

    expect($decision->isAutomatic())->toBeFalse()
        ->and($decision->reason)->toContain('critical');
});

it('escalates risk one level on the default branch', function () {
    $team = Team::factory()->create();
    RemediationPolicy::factory()->anyBranch()->create([
        'team_id' => $team->id, 'action_type' => 'retry_job',
        'mode' => 'auto', 'max_risk' => 'low', 'min_confidence' => 0.0,
    ]);

    // Invariant 3. retry_job is LOW, so it passes a max_risk of low on a feature
    // branch and must not on main — the same retry there changes what everyone
    // else is building against.
    [$feature, $featureFailure] = gateFixture($team, ref: 'feature/x');
    expect(decide($feature, $featureFailure)->isAutomatic())->toBeTrue();

    [$main, $mainFailure] = gateFixture($team, ref: 'main');
    $decision = decide($main, $mainFailure);

    expect($decision->isAutomatic())->toBeFalse()
        ->and($decision->reason)->toContain('medium');
});

it('requires approval below the confidence threshold', function () {
    $team = Team::factory()->create();
    RemediationPolicy::factory()->anyBranch()->create([
        'team_id' => $team->id, 'action_type' => 'retry_job',
        'mode' => 'auto', 'max_risk' => 'high', 'min_confidence' => 0.900,
    ]);
    [$rec, $failure] = gateFixture($team, ['confidence' => 0.72]);

    $decision = decide($rec, $failure);

    // The number in the message is the point: "72% is below the 90% threshold"
    // tells a developer what would change the answer.
    expect($decision->needsApproval())->toBeTrue()
        ->and($decision->reason)->toContain('72%')->toContain('90%');
});

it('treats a missing confidence as no confidence', function () {
    $team = Team::factory()->create();
    RemediationPolicy::factory()->anyBranch()->create([
        'team_id' => $team->id, 'action_type' => 'retry_job',
        'mode' => 'auto', 'max_risk' => 'high', 'min_confidence' => 0.500,
    ]);
    [$rec, $failure] = gateFixture($team, ['confidence' => null]);

    expect(decide($rec, $failure)->needsApproval())->toBeTrue();
});

it('requires approval on a blocked branch even when everything else passes', function () {
    $team = Team::factory()->create();
    RemediationPolicy::factory()->create([
        'team_id' => $team->id, 'action_type' => 'retry_job',
        'mode' => 'auto', 'max_risk' => 'critical', 'min_confidence' => 0.0,
        'allowed_branches' => ['*'], 'blocked_branches' => ['release/*'],
    ]);
    [$rec, $failure] = gateFixture($team, ref: 'release/2.1');

    $decision = decide($rec, $failure);

    expect($decision->needsApproval())->toBeTrue()
        ->and($decision->reason)->toContain('release/2.1');
});

it('requires approval for a branch outside the allowed list', function () {
    $team = Team::factory()->create();
    RemediationPolicy::factory()->create([
        'team_id' => $team->id, 'action_type' => 'retry_job',
        'mode' => 'auto', 'max_risk' => 'critical', 'min_confidence' => 0.0,
        'allowed_branches' => ['feature/*'], 'blocked_branches' => [],
    ]);
    [$rec, $failure] = gateFixture($team, ref: 'hotfix/urgent');

    expect(decide($rec, $failure)->needsApproval())->toBeTrue();
});

it('counts approved actions against the daily limit, not just executed ones', function () {
    $team = Team::factory()->create();
    RemediationPolicy::factory()->anyBranch()->create([
        'team_id' => $team->id, 'action_type' => 'retry_job',
        'mode' => 'auto', 'max_risk' => 'critical', 'min_confidence' => 0.0, 'max_per_day' => 2,
    ]);
    [$rec, $failure] = gateFixture($team);

    expect(decide($rec, $failure)->isAutomatic())->toBeTrue();

    // Invariant 4. Approved-but-not-yet-run must count, or a reject-and-reapprove
    // loop spends the budget without ever appearing to.
    Remediation::factory()->count(2)->create([
        'team_id' => $team->id,
        'project_id' => $failure->project_id,
        'failure_id' => $failure->id,
        'action_type' => 'retry_job',
        'status' => 'approved',
    ]);

    $decision = decide($rec, $failure);

    expect($decision->needsApproval())->toBeTrue()
        ->and($decision->reason)->toContain('Daily automatic limit');
});

it('does not count rejected or failed actions against the daily limit', function () {
    $team = Team::factory()->create();
    RemediationPolicy::factory()->anyBranch()->create([
        'team_id' => $team->id, 'action_type' => 'retry_job',
        'mode' => 'auto', 'max_risk' => 'critical', 'min_confidence' => 0.0, 'max_per_day' => 1,
    ]);
    [$rec, $failure] = gateFixture($team);

    foreach (['rejected', 'failed', 'cancelled', 'expired'] as $status) {
        Remediation::factory()->create([
            'team_id' => $team->id, 'project_id' => $failure->project_id,
            'failure_id' => $failure->id, 'action_type' => 'retry_job', 'status' => $status,
        ]);
    }

    // An action that never happened consumed no budget.
    expect(decide($rec, $failure)->isAutomatic())->toBeTrue();
});

it('lets a project policy override the team default', function () {
    $team = Team::factory()->create();
    [$rec, $failure] = gateFixture($team);

    RemediationPolicy::factory()->anyBranch()->create([
        'team_id' => $team->id, 'project_id' => null, 'action_type' => 'retry_job',
        'mode' => 'approval', 'max_risk' => 'critical', 'min_confidence' => 0.0,
    ]);

    expect(decide($rec, $failure)->needsApproval())->toBeTrue();

    // Invariant 5, first half: the more specific policy wins.
    RemediationPolicy::factory()->anyBranch()->create([
        'team_id' => $team->id, 'project_id' => $failure->project_id,
        'action_type' => 'retry_job',
        'mode' => 'auto', 'max_risk' => 'critical', 'min_confidence' => 0.0,
    ]);

    expect(decide($rec, $failure)->isAutomatic())->toBeTrue();
});

it('never lets a project policy re-permit what the workspace forbids', function () {
    $team = Team::factory()->create();
    [$rec, $failure] = gateFixture($team);

    RemediationPolicy::factory()->forbidden()->create([
        'team_id' => $team->id, 'project_id' => null, 'action_type' => 'retry_job',
    ]);
    RemediationPolicy::factory()->anyBranch()->create([
        'team_id' => $team->id, 'project_id' => $failure->project_id,
        'action_type' => 'retry_job',
        'mode' => 'auto', 'max_risk' => 'critical', 'min_confidence' => 0.0,
    ]);

    // Invariant 5, second half. Overriding may tighten or loosen a threshold; it
    // may not overturn a ban. Otherwise a workspace-wide prohibition is advice.
    expect(decide($rec, $failure)->isForbidden())->toBeTrue();
});

it('never reads another workspace\'s policy', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();

    // The policy table has no global team scope on purpose — the evaluator runs
    // from queued jobs where no team is bound — so the explicit filter is the
    // only thing standing between workspaces here.
    RemediationPolicy::factory()->anyBranch()->create([
        'team_id' => $other->id, 'action_type' => 'retry_job',
        'mode' => 'auto', 'max_risk' => 'critical', 'min_confidence' => 0.0,
    ]);
    [$rec, $failure] = gateFixture($team);

    expect(decide($rec, $failure)->isForbidden())->toBeTrue();
});

it('requires approval when the branch is unknown', function () {
    $team = Team::factory()->create();
    RemediationPolicy::factory()->anyBranch()->create([
        'team_id' => $team->id, 'action_type' => 'retry_job',
        'mode' => 'auto', 'max_risk' => 'critical', 'min_confidence' => 0.0,
    ]);

    // `pipelines.ref` is NOT NULL, so a missing branch reaches us as an empty
    // string rather than null — which is what a generic webhook with no ref
    // field produces. `!$ref` has to catch both.
    [$rec, $failure] = gateFixture($team, ref: '');

    // The branch rules are why automatic execution is safe at all. With no
    // branch to check, there is nothing to be safe about.
    expect(decide($rec, $failure)->needsApproval())->toBeTrue()
        ->and(decide($rec, $failure)->reason)->toContain('unknown');
});

it('stores the decision on the recommendation for the UI to render', function () {
    $team = Team::factory()->create();
    RemediationPolicy::factory()->anyBranch()->create([
        'team_id' => $team->id, 'action_type' => 'retry_job',
        'mode' => 'auto', 'max_risk' => 'critical', 'min_confidence' => 0.0,
    ]);
    [$rec] = gateFixture($team);

    app(RemediationPolicyEvaluator::class)->annotate($rec);

    expect($rec->fresh()->policy_decision)->toBe('auto_allowed')
        ->and($rec->fresh()->policy_reason)->toBe('Within policy.');
});
