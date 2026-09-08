<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActionType;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Larastan reads neither the `casts()` method's enum entries nor
 * `immutable_datetime` at level 5, so without these it infers the raw
 * column types and every enum method call on them looks like a call on a
 * string.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $project_id
 * @property ActionType $action_type
 * @property string $mode
 * @property Severity $max_risk
 * @property float $min_confidence
 * @property int $max_per_day
 * @property array<int,string> $allowed_branches
 * @property array<int,string> $blocked_branches
 * @property bool $enabled
 * @property int|null $created_by
 */
class RemediationPolicy extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action_type' => ActionType::class,
            'max_risk' => Severity::class,
            'min_confidence' => 'float',
            'allowed_branches' => 'array',
            'blocked_branches' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
