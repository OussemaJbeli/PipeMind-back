<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisEvidence extends Model
{
    use HasFactory;

    protected $table = 'analysis_evidence';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }

    public $timestamps = false;

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function relatedFailure(): BelongsTo
    {
        return $this->belongsTo(Failure::class, 'related_failure_id');
    }
}
