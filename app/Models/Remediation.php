<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActionType;
use App\Enums\RemediationStatus;
use App\Enums\Severity;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
