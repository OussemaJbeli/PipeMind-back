<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActionType;
use App\Enums\RemediationStatus;
use App\Enums\Severity;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Larastan reads neither the `casts()` method's enum entries nor
 * `immutable_datetime` at level 5, so without these it infers the raw column
 * types. That is not cosmetic: it inferred `status` as `string`, concluded the
 * enum comparison in ExecuteRemediation could never match, and reported the
 * whole body of the job as unreachable and its helpers as unused.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property int $project_id
 * @property int $failure_id
 * @property int|null $recommendation_id
 * @property ActionType $action_type
 * @property Severity $risk
 * @property RemediationStatus $status
 * @property string|null $policy_decision
 * @property string|null $policy_reason
 * @property array<string,mixed> $payload
 * @property array<string,mixed>|null $result
 * @property string|null $error
 * @property int|null $requested_by
 * @property int|null $approved_by
 * @property int|null $rejected_by
 * @property string|null $rejection_reason
 * @property int|null $resulting_pipeline_id
 * @property bool|null $outcome_success
 * @property array<int,array<string,mixed>> $audit
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $rejected_at
 * @property CarbonImmutable|null $executed_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property Carbon|null $created_at
 * @property-read Project|null $project
 * @property-read Failure|null $failure
 * @property-read Recommendation|null $recommendation
 * @property-read User|null $requester
 * @property-read User|null $approver
 * @property-read User|null $rejecter
 * @property-read Pipeline|null $resultingPipeline
 */
class Remediation extends Model
{
    use BelongsToTeam, HasFactory, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action_type' => ActionType::class,
            'risk' => Severity::class,
            'status' => RemediationStatus::class,
            'payload' => 'array',
            'result' => 'array',
            'audit' => 'array',
            'outcome_success' => 'boolean',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function failure(): BelongsTo
    {
        return $this->belongsTo(Failure::class);
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function resultingPipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'resulting_pipeline_id');
    }

    /**
     * A stale approval executing against a moved-on codebase is a hazard.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === RemediationStatus::PENDING_APPROVAL;
    }

    /** Append-only trail. Every state transition writes here. */
    public function appendAudit(string $event, ?int $userId = null, array $meta = []): void
    {
        $this->update([
            'audit' => [...($this->audit ?? []), [
                'event' => $event,
                'user_id' => $userId,
                'at' => now()->toIso8601String(),
                'meta' => $meta,
            ]],
        ]);
    }
}
