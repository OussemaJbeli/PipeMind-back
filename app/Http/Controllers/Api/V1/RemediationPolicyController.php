<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActionType;
use App\Enums\RemediationStatus;
use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Models\Remediation;
use App\Models\RemediationPolicy;
use App\Services\Remediation\RemediationExecutorRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Workspace policy settings. Owner-only, because these decide what the system
 * is allowed to do to your repositories without asking again.
 */
class RemediationPolicyController extends Controller
{
    public function index(Request $request): array
    {
        $teamId = $request->user()->current_team_id;

        // RemediationPolicy has no global team scope by design — the evaluator
        // runs from queued jobs with no team bound — so every query here filters
        // explicitly. Removing this exposes other workspaces' policies.
        $existing = RemediationPolicy::where('team_id', $teamId)
            ->whereNull('project_id')
            ->get()
            ->keyBy(fn (RemediationPolicy $p) => $p->action_type->value);

        // Every action type is listed, not just the configured ones. An action
        // absent from the table is FORBIDDEN, and a settings screen that hides
        // it would make that look like an oversight rather than a decision.
        $rows = collect(ActionType::cases())
            ->filter(fn (ActionType $type) => $type->isMutating() || $type === ActionType::INVESTIGATE)
            ->map(function (ActionType $type) use ($existing) {
                $policy = $existing->get($type->value);

                return [
                    'action_type' => $type->value,
                    'label' => $type->label(),
                    'intrinsic_risk' => $type->risk()->value,
                    'configured' => $policy !== null,
                    'uuid' => $policy?->id,
                    'mode' => $policy->mode ?? 'forbidden',
                    'max_risk' => $policy?->max_risk->value ?? 'low',
                    'min_confidence' => (float) ($policy->min_confidence ?? 1.0),
                    'max_per_day' => (int) ($policy->max_per_day ?? 0),
                    'allowed_branches' => $policy->allowed_branches ?? ['*'],
                    'blocked_branches' => $policy->blocked_branches ?? ['main', 'master', 'production'],
                    'enabled' => (bool) ($policy->enabled ?? false),
                    'executable' => app(RemediationExecutorRegistry::class)
                        ->handles($type),
                ];
            })
            ->values();

        return ['data' => $rows->all()];
    }

    public function update(Request $request, string $actionType): array
    {
        $type = ActionType::tryFrom($actionType);

        if (! $type) {
            abort(404);
        }

        $data = $request->validate([
            'mode' => ['required', Rule::in(['auto', 'approval', 'forbidden'])],
            'max_risk' => ['required', Rule::in(Severity::values())],
            'min_confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'max_per_day' => ['required', 'integer', 'min:0', 'max:100'],
            'allowed_branches' => ['required', 'array', 'max:20'],
            'allowed_branches.*' => ['string', 'max:255'],
            'blocked_branches' => ['present', 'array', 'max:20'],
            'blocked_branches.*' => ['string', 'max:255'],
            'enabled' => ['required', 'boolean'],
        ]);

        $teamId = $request->user()->current_team_id;

        $policy = DB::transaction(function () use ($teamId, $type, $data, $request) {
            $policy = RemediationPolicy::firstOrNew([
                'team_id' => $teamId,
                'project_id' => null,
                'action_type' => $type->value,
            ]);

            $policy->fill([...$data, 'created_by' => $policy->created_by ?? $request->user()->id]);
            $policy->save();

            return $policy;
        });

        $cancelled = $this->cancelNowForbidden($teamId, $type, $policy);

        activity_log(null, 'policy.updated', 'warning', $type->label(),
            sprintf('%s policy set to %s%s', $type->label(), $data['mode'],
                $cancelled ? ", cancelling {$cancelled} pending remediation(s)" : ''),
            $policy);

        return ['data' => [
            'action_type' => $type->value,
            'mode' => $policy->mode,
            'cancelled_remediations' => $cancelled,
        ]];
    }

    /**
     * Tightening a policy cancels work it would no longer permit.
     *
     * Without this, an approval granted under the old policy sits in the queue
     * and executes anyway — so turning an action off would not actually turn it
     * off. ExecuteRemediation re-checks as well, but leaving these rows
     * "approved" would also misreport what is about to happen.
     */
    private function cancelNowForbidden(int $teamId, ActionType $type, RemediationPolicy $policy): int
    {
        if ($policy->enabled && $policy->mode !== 'forbidden') {
            return 0;
        }

        $affected = Remediation::query()
            ->where('team_id', $teamId)
            ->where('action_type', $type->value)
            ->whereIn('status', [
                RemediationStatus::PENDING_APPROVAL->value,
                RemediationStatus::APPROVED->value,
                RemediationStatus::QUEUED->value,
            ])
            ->get();

        foreach ($affected as $remediation) {
            $remediation->update([
                'status' => RemediationStatus::CANCELLED,
                'error' => "The {$type->label()} policy was changed to forbid this action.",
                'completed_at' => now(),
            ]);
            $remediation->appendAudit('cancelled', null, ['reason' => 'policy tightened']);
        }

        return $affected->count();
    }
}
