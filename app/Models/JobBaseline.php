<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobBaseline extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mean_duration_seconds' => 'float',
            'stddev_duration' => 'float',
            'median_duration_seconds' => 'float',
            'mad_duration' => 'float',
            'p95_duration_seconds' => 'float',
            'failure_rate' => 'float',
            'retry_rate' => 'float',
            'last_computed_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
