<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActionType;
use App\Enums\Severity;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Recommendation extends Model
{
    use HasFactory, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'action_type' => ActionType::class,
            'risk' => Severity::class,
            'confidence' => 'float',
            'affected_files' => 'array',
            'action_payload' => 'array',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function failure(): BelongsTo
    {
        return $this->belongsTo(Failure::class);
    }

    public function remediation(): HasOne
    {
        return $this->hasOne(Remediation::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
