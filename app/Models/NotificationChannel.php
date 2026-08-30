<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationChannel extends Model
{
    use BelongsToTeam,  HasFactory, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'events' => 'array',
            'enabled' => 'boolean',
            'last_sent_at' => 'immutable_datetime',
        ];
    }

    protected $hidden = ['config'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
