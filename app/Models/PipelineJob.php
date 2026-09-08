<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JobStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ScopedThroughProject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Larastan reads neither the `casts()` method's enum entries nor
 * `immutable_datetime` at level 5, so without these it infers the raw
 * column types and every enum method call on them looks like a call on a
 * string.
 *
 * @property int $id
 * @property int $pipeline_id
 * @property string|null $external_id
 * @property string $name
 * @property JobStatus $status
 * @property array<string,mixed>|null $raw_payload
 * @property-read JobLog|null $log
 */
class PipelineJob extends Model
{
    use HasFactory, HasUuid, ScopedThroughProject;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'runner_tags' => 'array',
            'raw_payload' => 'array',
            'allow_failure' => 'boolean',
            'is_retryable' => 'boolean',
            'log_fetched' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    protected static function teamScopeRelation(): string
    {
        return 'pipeline.project';
    }

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function log(): HasOne
    {
        return $this->hasOne(JobLog::class, 'job_id');
    }

    public function failure(): HasOne
    {
        return $this->hasOne(Failure::class, 'job_id');
    }
}
