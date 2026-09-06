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
