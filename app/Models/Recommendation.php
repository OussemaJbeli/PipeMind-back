<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActionType;
use App\Enums\Severity;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ScopedThroughProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Larastan reads neither the `casts()` method's enum entries nor
 * `immutable_datetime` at level 5, so without these it infers the raw column
 * types. That is not cosmetic: it inferred `status` as `string`, concluded the
 * enum comparison in ExecuteRemediation could never match, and reported the
 * whole body of the job as unreachable and its helpers as unused.
 *
 * @property int $id
 * @property string $uuid
 * @property int $analysis_id
 * @property int $failure_id
 * @property string $title
 * @property string|null $description
 * @property string|null $rationale
 * @property ActionType $action_type
 * @property Severity $risk
 * @property float|null $confidence
 * @property array<int,string> $affected_files
 * @property string|null $patch
 * @property array<string,mixed> $action_payload
 * @property string $status
 * @property string|null $policy_decision
 * @property string|null $policy_reason
 * @property CarbonImmutable|null $decided_at
 * @property-read Failure|null $failure
 * @property-read Analysis|null $analysis
 * @property-read Remediation|null $remediation
 * @property-read User|null $decider
 */
class Recommendation extends Model
{
    use HasFactory, HasUuid, ScopedThroughProject;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action_type' => ActionType::class,
            'risk' => Severity::class,
            'confidence' => 'float',
            'affected_files' => 'array',
            'action_payload' => 'array',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function failure(): BelongsTo
    {
        return $this->belongsTo(Failure::class);
    }

    public function remediation(): HasOne
    {
        return $this->hasOne(Remediation::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Recommendations carry no team_id, so without this `/recommendations/{uuid}`
     * would resolve any workspace's row — and accepting one triggers an action
     * against their repository.
     */
    protected static function teamScopeRelation(): string
    {
        return 'failure.project';
    }
}
