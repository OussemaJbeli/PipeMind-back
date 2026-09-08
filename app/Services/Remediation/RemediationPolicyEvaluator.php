<?php

declare(strict_types=1);

namespace App\Services\Remediation;

use App\Enums\RemediationStatus;
use App\Enums\Severity;
use App\Models\Failure;
use App\Models\Recommendation;
use App\Models\Remediation;
use App\Models\RemediationPolicy;
use Illuminate\Support\Str;

/**
 * The deterministic gate between a suggestion and an action.
 *
 * Laravel is the execution boundary: the model proposes, this class decides, and
 * nothing it forbids can be reached by asking differently. Two rules make that
 * true. Risk comes from the action type rather than from the response, so a model
 * cannot label a production rollback "low risk" and talk its way through. And a
 * missing policy is FORBIDDEN, so a gap in the table cannot become permission.
 */
class RemediationPolicyEvaluator
{
    public function evaluate(Recommendation $recommendation, Failure $failure): PolicyDecision
    {
        $project = $failure->project;

        if (! $project) {
            return PolicyDecision::forbidden('The failure has no project to act on.');
        }

        $action = $recommendation->action_type;

        // RemediationPolicy carries team_id but deliberately has no global team
        // scope, because this runs from queued jobs where no team is bound.
        // Filtering explicitly is the whole protection — do not remove it.
        $policies = RemediationPolicy::query()
            ->where('team_id', $project->team_id)
            ->where('action_type', $action->value)
            ->where(fn ($q) => $q->where('project_id', $project->id)->orWhereNull('project_id'))
            ->get();

        // A workspace-level "forbidden" is absolute: a project override may
        // tighten a policy or relax a threshold, but it may never re-permit an
        // action the workspace has banned outright.
        if ($policies->contains(fn (RemediationPolicy $p) => $p->enabled && $p->mode === 'forbidden')) {
            return PolicyDecision::forbidden(
                "{$action->label()} is not permitted in this workspace."
            );
        }

        // Project policy wins over the team default.
        $policy = $policies->sortBy(fn (RemediationPolicy $p) => $p->project_id === null)->first();

        if (! $policy || ! $policy->enabled) {
            return PolicyDecision::forbidden('No enabled policy for this action type.');
        }

        $risk = $this->effectiveRisk($recommendation, $failure);

        if ($risk->exceeds($policy->max_risk)) {
            return PolicyDecision::requiresApproval(sprintf(
                'Risk %s exceeds the automatic limit (%s).', $risk->value, $policy->max_risk->value
            ));
        }

        $confidence = (float) ($recommendation->confidence ?? 0.0);

        if ($confidence < $policy->min_confidence) {
            return PolicyDecision::requiresApproval(sprintf(
                'Confidence %.0f%% is below the %.0f%% threshold.',
                $confidence * 100, $policy->min_confidence * 100
            ));
        }

        $ref = $failure->pipeline?->ref;

        // No branch means nothing to check the branch rules against, and the
        // rules are the reason automatic execution is safe. Fall back to a human.
        if (! $ref) {
            return PolicyDecision::requiresApproval('The branch for this failure is unknown.');
        }

        if ($this->matchesAny($ref, $policy->blocked_branches ?? [])) {
            return PolicyDecision::requiresApproval("Branch '{$ref}' requires explicit approval.");
        }

        if (! $this->matchesAny($ref, $policy->allowed_branches ?? ['*'])) {
            return PolicyDecision::requiresApproval("Branch '{$ref}' is not in the allowed list.");
        }

        if (($used = $this->executedToday($project->id, $action->value)) >= $policy->max_per_day) {
            return PolicyDecision::requiresApproval(sprintf(
                'Daily automatic limit reached (%d of %d).', $used, $policy->max_per_day
            ));
        }

        return $policy->mode === 'auto'
            ? PolicyDecision::autoAllowed('Within policy.')
            : PolicyDecision::requiresApproval('This action always requires approval.');
    }

    /**
     * Stores the decision on the recommendation so the failure page can render
     * the gate without evaluating a policy per row on every request.
     *
     * A cache, never the authority: ExecuteRemediation re-evaluates before it
     * acts.
     */
    public function annotate(Recommendation $recommendation): PolicyDecision
    {
        $failure = $recommendation->failure ?? $recommendation->loadMissing('failure')->failure;

        $decision = $failure
            ? $this->evaluate($recommendation, $failure)
            : PolicyDecision::forbidden('The recommendation has no failure to act on.');

        $recommendation->forceFill([
            'policy_decision' => $decision->decision,
            'policy_reason' => Str::limit($decision->reason, 250),
        ])->save();

        return $decision;
    }

    /**
     * Risk is a property of the action type, escalated one level on the default
     * branch.
     *
     * The escalation is the point: retrying a job on a feature branch is
     * routine, and the same retry on `main` is a change to what everyone else
     * is building against.
     */
    public function effectiveRisk(Recommendation $recommendation, Failure $failure): Severity
    {
        $risk = $recommendation->action_type->risk();
        $ref = $failure->pipeline?->ref;
        $default = $failure->project?->default_branch;

        return ($ref !== null && $default !== null && $ref === $default)
            ? $risk->escalate()
            : $risk;
    }

    /**
     * Counts actions that were approved as well as those that ran.
     *
     * Counting only executions would let a rejected-then-reapproved loop spend
     * the budget without ever appearing to.
     */
    private function executedToday(int $projectId, string $actionType): int
    {
        return Remediation::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->where('action_type', $actionType)
            ->whereDate('created_at', today())
            ->whereIn('status', [
                RemediationStatus::APPROVED->value,
                RemediationStatus::QUEUED->value,
                RemediationStatus::EXECUTING->value,
                RemediationStatus::SUCCEEDED->value,
            ])
            ->count();
    }

    /**
     * @param  array<int,mixed>  $patterns  straight from jsonb, so not
     *                                      guaranteed to hold strings however the column is documented
     */
    private function matchesAny(string $ref, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && Str::is($pattern, $ref)) {
                return true;
            }
        }

        return false;
    }
}
