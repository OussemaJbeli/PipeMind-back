<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Larastan reads the `casts()` method for most types but not for
 * `immutable_datetime`, so without this it infers `string` and every
 * date method called on the field is a false positive.
 *
 * @property CarbonImmutable|null $last_pipeline_at
 */
class Project extends Model
{
    use BelongsToTeam, HasFactory, HasUuid, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tech_stack' => 'array',
            'analyze_on_branches' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'auto_analyze' => 'boolean',
            'success_rate' => 'float',
            'last_pipeline_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return BelongsTo<AiProvider, $this> */
    public function aiProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastPipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'last_pipeline_id');
    }

    public function pipelines(): HasMany
    {
        return $this->hasMany(Pipeline::class);
    }

    public function failures(): HasMany
    {
        return $this->hasMany(Failure::class);
    }

    public function anomalies(): HasMany
    {
        return $this->hasMany(Anomaly::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ProjectMetricDaily::class);
    }

    public function baselines(): HasMany
    {
        return $this->hasMany(JobBaseline::class);
    }

    public function knowledgeDocuments(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function remediations(): HasMany
    {
        return $this->hasMany(Remediation::class);
    }

    public function jobs(): HasManyThrough
    {
        return $this->hasManyThrough(PipelineJob::class, Pipeline::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function initials(): string
    {
        return strtoupper(mb_substr($this->name, 0, 2));
    }

    /** Does this ref match the project's analyse-on-branches patterns? */
    public function shouldAnalyzeRef(string $ref): bool
    {
        foreach ($this->analyze_on_branches ?: ['*'] as $pattern) {
            if ($pattern === '*' || Str::is($pattern, $ref)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Right now", deliberately distinct from the 30-day success_rate. A project at
     * 98% whose last pipeline just failed must not render green.
     */
    public function computeHealthStatus(): string
    {
        if (! $this->last_pipeline_at) {
            return 'unknown';
        }

        if ($this->lastPipeline?->status === 'failed') {
            return 'failing';
        }

        return $this->success_rate < config('pipemind.health.degraded_below_success_rate')
            ? 'degraded'
            : 'healthy';
    }
}
