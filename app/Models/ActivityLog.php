<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityLevel;
use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Larastan reads neither the `casts()` method's enum entries nor
 * `immutable_datetime` at level 5, so without these it infers the raw
 * column types.
 *
 * @property string $uuid
 * @property string $action
 * @property ActivityLevel $level
 * @property string $title
 * @property string|null $description
 * @property string $actor_type
 * @property CarbonImmutable|null $created_at
 */
class ActivityLog extends Model
{
    use BelongsToTeam,  HasFactory, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'level' => ActivityLevel::class,
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public $timestamps = false;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
