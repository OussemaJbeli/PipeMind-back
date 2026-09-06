<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Tenancy for models that carry no `team_id` of their own.
 *
 * Pipelines and jobs belong to a team only through their project, so `TeamScope`
 * has no column to filter on and route model binding would happily resolve
 * another team's UUID. Job logs are the most sensitive data in the system, so
 * "no global scope applies" must not quietly mean "no tenancy applies".
 *
 * Applied at route-binding resolution rather than as a global scope: internal
 * queries (queued jobs, webhook ingestion) legitimately run with no team bound,
 * and a global scope would silently return nothing for them.
 *
 * Implementing classes define `teamScopeRelation()` — the relation path from
 * this model to `projects`.
 */
trait ScopedThroughProject
{
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $teamId = currentTeamId();

        if (! $teamId) {
            return null;
        }

        return $this->newQuery()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->whereHas(
                static::teamScopeRelation(),
                fn ($query) => $query->where('projects.team_id', $teamId),
            )
            ->first();
    }

    /** Relation path from this model to the owning project. */
    abstract protected static function teamScopeRelation(): string;
}
