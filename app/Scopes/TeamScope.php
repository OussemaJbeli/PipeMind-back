<?php

declare(strict_types=1);

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TeamScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // No bound team means no session and no explicit binding — a console
        // command, a webhook before the integration is resolved, or a test.
        // Those paths use withoutGlobalScopes() or withTeam() deliberately.
        if ($teamId = currentTeamId()) {
            $builder->where($model->getTable().'.team_id', $teamId);
        }
    }
}
