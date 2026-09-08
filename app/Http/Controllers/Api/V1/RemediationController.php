<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\RemediationStatus;
use App\Events\RemediationStatusChanged;
use App\Exceptions\Remediation\RemediationExpired;
use App\Exceptions\Remediation\RemediationForbidden;
use App\Http\Controllers\Controller;
use App\Jobs\ExecuteRemediation;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Remediation;
use App\Services\Remediation\RemediationExecutorRegistry;
use App\Services\Remediation\RemediationPolicyEvaluator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The approval flow. Laravel is the execution boundary for every action here.
 */
class RemediationController extends Controller
{
    public function __construct(
        private readonly RemediationPolicyEvaluator $evaluator,
        private readonly RemediationExecutorRegistry $registry,
    ) {}

    /** Pending first — it is the only section that needs somebody to do something. */
    public function index(Request $request, Project $project): array
    {
        $remediations = Remediation::query()
            ->where('project_id', $project->id)
            ->when($request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')))
            ->with(['recommendation', 'failure.pipeline', 'requester', 'approver', 'rejecter', 'resultingPipeline'])
            ->orderByRaw("CASE WHEN status = 'pending_approval' THEN 0 ELSE 1 END")
            ->latest('id')
            ->limit(100)
            ->get();

        return [
            'data' => $remediations->map(fn (Remediation $r) => $this->present($r))->all(),
            'meta' => [
                'pending' => $remediations->where('status', RemediationStatus::PENDING_APPROVAL)->count(),
            ],
        ];
    }

    public function show(Remediation $remediation): array
    {
        $remediation->load([
            'recommendation', 'failure.pipeline', 'requester', 'approver', 'rejecter', 'resultingPipeline',
        ]);

        return ['data' => [...$this->present($remediation), 'audit' => $remediation->audit ?? []]];
    }

    /**
     * Turns a recommendation into a remediation, and runs it if policy allows.
     *
     * The response deliberately differs by outcome rather than always returning
     * 201: a forbidden action must not look like a queued one.
     */
    public function accept(Request $request, Recommendation $recommendation): JsonResponse
    {
        $failure = $recommendation->loadMissing('failure.project.team')->failure;
        $project = $failure?->project;

        if (! $project) {
            throw new RemediationForbidden('This recommendation has no project to act on.');
        }

        if ($existing = $this->openRemediationFor($recommendation)) {
            // Accepting twice is a double-click or an impatient second tab, not
            // a request for two pull requests.
            return response()->json(['data' => $this->present($existing)], 200);
        }

        $decision = $this->evaluator->evaluate($recommendation, $failure);

        $recommendation->forceFill([
            'policy_decision' => $decision->decision,
            'policy_reason' => $decision->reason,
        ])->save();

        if ($decision->isForbidden()) {
            throw new RemediationForbidden($decision->reason);
        }

        if (! $this->registry->handles($recommendation->action_type)) {
            throw new RemediationForbidden(sprintf(
                "PipeMind cannot perform '%s' itself — apply this one by hand.",
                $recommendation->action_type->value,
            ));
        }

        $automatic = $decision->isAutomatic();

        $remediation = Remediation::create([
            'project_id' => $project->id,
            'failure_id' => $failure->id,
            'recommendation_id' => $recommendation->id,
            'action_type' => $recommendation->action_type,
            // From the action type, escalated for the branch — never the
            // model's own claim about its risk.
            'risk' => $this->evaluator->effectiveRisk($recommendation, $failure),
            'policy_decision' => $decision->decision,
            'policy_reason' => $decision->reason,
            'status' => $automatic ? RemediationStatus::APPROVED : RemediationStatus::PENDING_APPROVAL,
            'requested_by' => $request->user()->id,
            'payload' => ['affected_files' => $recommendation->affected_files ?? []],
            // An approval is permission to act now. A day later the codebase has
            // moved and the same action is a different action.
            'expires_at' => now()->addDay(),
            // No approver on an automatic run: the policy approved it, not a
            // person, and recording a name here would misattribute the decision.
            'approved_at' => $automatic ? now() : null,
        ]);

        $remediation->appendAudit($automatic ? 'auto_approved' : 'requested', $request->user()->id, [
            'decision' => $decision->decision,
            'reason' => $decision->reason,
        ]);

        activity_log($project, $automatic ? 'remediation.auto_approved' : 'remediation.requested',
            'info', $recommendation->title, $decision->reason, $remediation);

        // Announced whether it runs now or waits: a pending approval has to
        // reach the badge of everyone who could approve it.
        RemediationStatusChanged::dispatch($remediation->loadMissing('project.team', 'recommendation'));

        if ($automatic) {
            ExecuteRemediation::dispatch($remediation->id);
        }

        return response()->json(['data' => $this->present($remediation->fresh([
            'recommendation', 'failure.pipeline', 'requester',
        ]))], 201);
    }

    public function approve(Request $request, Remediation $remediation): array
    {
        $this->assertPending($remediation);

        // The requester cannot approve their own request. Self-approval makes
        // the gate a formality, and the whole point of approval is that a second
        // person saw it.
        if ($remediation->requested_by === $request->user()->id) {
            throw new RemediationForbidden(
                'You requested this remediation, so somebody else has to approve it.'
            );
        }

        $remediation->update([
            'status' => RemediationStatus::APPROVED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);
        $remediation->appendAudit('approved', $request->user()->id);
        RemediationStatusChanged::dispatch($remediation->loadMissing('project.team', 'recommendation'));

        activity_log($remediation->project, 'remediation.approved', 'info',
            $remediation->recommendation->title ?? $remediation->action_type->label(),
            "Approved by {$request->user()->name}", $remediation);

        ExecuteRemediation::dispatch($remediation->id);

        return ['data' => $this->present($remediation->fresh([
            'recommendation', 'failure.pipeline', 'requester', 'approver',
        ]))];
    }

    public function reject(Request $request, Remediation $remediation): array
    {
        $this->assertPending($remediation);

        // A reason is required. "Rejected" with no explanation teaches nobody
        // anything, and these rejections are the record of what the policy
        // should have caught.
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $remediation->update([
            'status' => RemediationStatus::REJECTED,
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
            'rejection_reason' => $data['reason'],
            'completed_at' => now(),
        ]);
        $remediation->appendAudit('rejected', $request->user()->id, ['reason' => $data['reason']]);
        RemediationStatusChanged::dispatch($remediation->loadMissing('project.team', 'recommendation'));

        activity_log($remediation->project, 'remediation.rejected', 'warning',
            $remediation->recommendation->title ?? $remediation->action_type->label(),
            $data['reason'], $remediation);

        return ['data' => $this->present($remediation->fresh([
            'recommendation', 'failure.pipeline', 'requester', 'rejecter',
        ]))];
    }

    /** Describes what would happen, without doing it. */
    public function dryRun(Remediation $remediation): array
    {
        if (! $this->registry->handles($remediation->action_type)) {
            throw new RemediationForbidden(sprintf(
                "PipeMind cannot perform '%s' itself.", $remediation->action_type->value,
            ));
        }

        $remediation->load(['project.integration', 'failure.pipeline', 'failure.job', 'recommendation']);
        $executor = $this->registry->for($remediation->action_type);

        if (! $executor->canExecute($remediation)) {
            return ['data' => [
                'possible' => false,
                'summary' => $executor->blockedReason($remediation),
            ]];
        }

        $result = $executor->execute($remediation, dryRun: true);

        return ['data' => ['possible' => true, 'summary' => $result->summary, ...$result->toArray()]];
    }

    private function assertPending(Remediation $remediation): void
    {
        if ($remediation->isExpired()) {
            $remediation->update(['status' => RemediationStatus::EXPIRED, 'completed_at' => now()]);
            $remediation->appendAudit('expired');

            throw new RemediationExpired;
        }

        if (! $remediation->isPending()) {
            throw ValidationException::withMessages([
                'status' => "This remediation is already {$remediation->status->value}.",
            ]);
        }
    }

    private function openRemediationFor(Recommendation $recommendation): ?Remediation
    {
        return Remediation::query()
            ->where('recommendation_id', $recommendation->id)
            ->whereIn('status', [
                RemediationStatus::PENDING_APPROVAL->value,
                RemediationStatus::APPROVED->value,
                RemediationStatus::QUEUED->value,
                RemediationStatus::EXECUTING->value,
                RemediationStatus::SUCCEEDED->value,
            ])
            ->first();
    }

    /** @return array<string,mixed> */
    private function present(Remediation $remediation): array
    {
        return [
            'uuid' => $remediation->uuid,
            'action_type' => $remediation->action_type->value,
            'action_label' => $remediation->action_type->label(),
            'risk' => $remediation->risk->value,
            'status' => $remediation->status->value,
            'policy_decision' => $remediation->policy_decision,
            'policy_reason' => $remediation->policy_reason,
            'result' => $remediation->result,
            'error' => $remediation->error,
            'rejection_reason' => $remediation->rejection_reason,
            'requested_by' => $remediation->requester?->name,
            'approved_by' => $remediation->approver?->name,
            'rejected_by' => $remediation->rejecter?->name,
            'expires_at' => $remediation->expires_at?->toIso8601String(),
            'executed_at' => $remediation->executed_at?->toIso8601String(),
            'completed_at' => $remediation->completed_at?->toIso8601String(),
            'created_at' => $remediation->created_at?->toIso8601String(),
            // null is "not verified yet", which is different from "did not fix".
            'outcome_success' => $remediation->outcome_success,
            'resulting_pipeline' => $remediation->resultingPipeline ? [
                'iid' => $remediation->resultingPipeline->iid,
                'status' => $remediation->resultingPipeline->status->value,
                'web_url' => $remediation->resultingPipeline->web_url,
            ] : null,
            'recommendation' => $remediation->recommendation ? [
                'uuid' => $remediation->recommendation->uuid,
                'title' => $remediation->recommendation->title,
                'description' => $remediation->recommendation->description,
                'confidence' => $remediation->recommendation->confidence !== null
                    ? (float) $remediation->recommendation->confidence
                    : null,
                'affected_files' => $remediation->recommendation->affected_files ?? [],
                'patch' => $remediation->recommendation->patch,
            ] : null,
            'failure' => $remediation->failure ? [
                'uuid' => $remediation->failure->uuid,
                'category' => $remediation->failure->category,
                'error_message' => $remediation->failure->error_message,
                'pipeline_iid' => $remediation->failure->pipeline?->iid,
                'ref' => $remediation->failure->pipeline?->ref,
            ] : null,
        ];
    }
}
