<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'provider' => $this->provider,
            'name' => $this->name,
            'base_url' => $this->base_url,
            // Self-hosted vs cloud changes the base url and sometimes the auth,
            // so the UI needs it as a first-class field.
            'instance_type' => $this->isCloudInstance() ? 'cloud' : 'self_hosted',
            'status' => $this->status,
            'scopes' => $this->scopes ?? [],
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'last_event_at' => $this->last_event_at?->toIso8601String(),
            'last_error' => $this->last_error,
            // A dead webhook is invisible otherwise: silence looks identical to
            // "nothing happened".
            'looks_stale' => $this->looksStale(),
            'webhook_url' => $this->webhookUrl(),
            'projects_count' => $this->whenCounted('projects'),
            'created_at' => $this->created_at?->toIso8601String(),

            // credentials and webhook_secret are in $hidden and never serialised.
        ];
    }
}
