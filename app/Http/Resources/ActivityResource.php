<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'action' => $this->action,
            'level' => $this->level->value,
            'title' => $this->title,
            'description' => $this->description,
            'actor_type' => $this->actor_type,
            'project' => $this->whenLoaded('project', fn () => [
                'uuid' => $this->project->uuid,
                'name' => $this->project->name,
                'slug' => $this->project->slug,
            ]),
            'subject_type' => $this->subject_type,
            'subject_uuid' => $this->subject_uuid,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
