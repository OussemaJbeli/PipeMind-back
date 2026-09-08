<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PipelineStatus;
use App\Models\Concerns\HasUuid;
use App\Models\Concerns\ScopedThroughProject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Larastan reads neither the `casts()` method's enum entries nor
 * `immutable_datetime` at level 5, so without these it infers the raw
 * column types and every enum method call on them looks like a call on a
 * string.
 *
 * @property int $id
 * @property string $uuid
 * @property int $project_id
 * @property string $external_id
 * @property int $iid
 * @property PipelineStatus $status
 * @property string $ref
 * @property string|null $commit_sha
 * @property string|null $commit_short_sha
 * @property string|null $commit_message
 * @property string|null $web_url
 * @property int|null $duration_seconds
 * @property bool $has_failure
 * @property array<string,mixed>|null $raw_payload
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
class Pipeline extends Model
{
    use HasFactory, HasUuid, ScopedThroughProject;

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

    protected static function teamScopeRelation(): string
    {
        return 'project';
    }

    /** @return BelongsTo<Project, $this> */
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
