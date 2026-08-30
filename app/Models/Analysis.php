<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalysisStatus;
use App\Enums\FailureCategory;
use App\Enums\Severity;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Analysis extends Model
{
    use BelongsToTeam, HasFactory, HasUuid;

    protected $table = 'analyses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'category' => FailureCategory::class,
            'severity' => Severity::class,
            'confidence' => 'float',
            'classification_confidence' => 'float',
            'cost_usd' => 'decimal:6',
            'raw_response' => 'array',
            'used_rag' => 'boolean',
            'cache_hit' => 'boolean',
            'is_transient' => 'boolean',
            'retry_recommended' => 'boolean',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function failure(): BelongsTo
    {
        return $this->belongsTo(Failure::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AnalysisFeedback::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(AnalysisEvidence::class)->orderBy('position');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class)->orderBy('position');
    }

    /** Words, not just a number: "92%" alone invites false precision. */
    public function confidenceLabel(): string
    {
        return match (true) {
            $this->confidence >= 0.85 => 'High confidence',
            $this->confidence >= 0.60 => 'Moderate confidence',
            default => 'Low confidence',
        };
    }
}
