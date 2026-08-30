<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $team = $this->currentTeam;

        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'initials' => $this->initials(),
            'job_title' => $this->job_title,
            'theme' => $this->theme,
            'timezone' => $this->timezone,
            'onboarded_at' => $this->onboarded_at?->toIso8601String(),

            'current_team' => $team ? [
                'uuid' => $team->uuid,
                'name' => $team->name,
                'slug' => $team->slug,
                'role' => $this->roleIn($team)?->value,
                'plan' => $team->plan,
                'privacy_mode' => $team->privacy_mode,
            ] : null,

            'teams' => $this->whenLoaded('teams', fn () => $this->teams->map(fn ($t) => [
                'uuid' => $t->uuid,
                'name' => $t->name,
                'slug' => $t->slug,
                'role' => $t->pivot->role,
            ])),

            // Flat list derived from the role matrix. The frontend calls
            // can('remediation.approve') against this and never reimplements
            // role logic — one source of truth.
            'permissions' => $this->permissions(),
        ];
    }
}
