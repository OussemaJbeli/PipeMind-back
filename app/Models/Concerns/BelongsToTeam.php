<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Team;
use App\Scopes\TeamScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTeam
{
    protected static function bootBelongsToTeam(): void
    {
        static::addGlobalScope(new TeamScope);

        static::creating(function ($model): void {
            if (empty($model->team_id) && $teamId = currentTeamId()) {
                $model->team_id = $teamId;
            }
        });
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
