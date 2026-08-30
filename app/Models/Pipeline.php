<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PipelineStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    use HasFactory, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PipelineStatus::class,
            'is_tag' => 'boolean',
            'has_failure' => 'boolean',
            'raw_payload' => 'array',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PipelineEvent::class);
    }

    public function failures(): HasMany
    {
        return $this->hasMany(Failure::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(CommitChange::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('position');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(PipelineJob::class)->orderBy('position');
    }

    public function failedJobs(): HasMany
    {
        return $this->hasMany(PipelineJob::class)->where('status', 'failed');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', ['queued', 'running']);
    }

    public function scopeTerminal(Builder $q): Builder
    {
        return $q->whereIn('status', ['success', 'failed', 'canceled', 'skipped', 'timeout']);
    }

    /** Highest-signal fact available to the analysis: was the last run green? */
    public function previousStatus(): ?string
    {
        return static::query()
            ->where('project_id', $this->project_id)
            ->where('ref', $this->ref)
            ->where('id', '<', $this->id)
            ->whereIn('status', ['success', 'failed'])
            ->latest('id')
            ->value('status');
    }
}
