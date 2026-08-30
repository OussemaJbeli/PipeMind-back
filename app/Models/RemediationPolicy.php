<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActionType;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemediationPolicy extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action_type' => ActionType::class,
            'max_risk' => Severity::class,
            'min_confidence' => 'float',
            'allowed_branches' => 'array',
            'blocked_branches' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
