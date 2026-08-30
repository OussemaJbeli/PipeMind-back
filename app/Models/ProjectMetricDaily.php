<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMetricDaily extends Model
{
    use HasFactory;

    protected $table = 'project_metrics_daily';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'success_rate' => 'float',
            'failures_by_category' => 'array',
            'ai_cost_usd' => 'decimal:6',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
