<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Severity;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Larastan reads neither the `casts()` method's enum entries nor
 * `immutable_datetime` at level 5, so without these it infers the raw
 * column types.
 *
 * @property string $uuid
 * @property string $type
 * @property Severity $severity
 * @property string $metric_name
 * @property string $title
 * @property string|null $description
 */
class Anomaly extends Model
{
    use BelongsToTeam,  HasFactory, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'observed_value' => 'float',
            'baseline_value' => 'float',
            'deviation_ratio' => 'float',
            'z_score' => 'float',
            'possible_causes' => 'array',
            'detected_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(PipelineJob::class, 'job_id');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
