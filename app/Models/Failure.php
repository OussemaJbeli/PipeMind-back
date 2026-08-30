<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FailureCategory;
use App\Enums\FailureStatus;
use App\Enums\Severity;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Failure extends Model
{
    use BelongsToTeam, HasFactory, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => FailureStatus::class,
            'severity' => Severity::class,
            'category' => FailureCategory::class,
            'is_flaky' => 'boolean',
            'is_transient' => 'boolean',
            'failed_at' => 'immutable_datetime',
            'detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

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

    public function signature(): BelongsTo
    {
        return $this->belongsTo(FailureSignature::class, 'signature_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(Analysis::class)->latest();
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class)->orderBy('position');
    }

    public function remediations(): HasMany
    {
        return $this->hasMany(Remediation::class);
    }

    public function embedding(): HasOne
    {
        return $this->hasOne(FailureEmbedding::class);
    }

    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(Analysis::class)->latestOfMany();
    }

    public function scopeUnresolved(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    public function scopeAnalyzable(Builder $q): Builder
    {
        // Flaky failures are excluded: analysing the same flake twenty times is the
        // fastest way to exhaust a budget for zero insight.
        return $q->where('is_flaky', false)
            ->whereIn('status', ['detected', 'queued', 'analysis_failed']);
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
