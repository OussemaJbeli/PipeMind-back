<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class ActivityLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'project_id' => Project::factory(),
            'actor_type' => 'system',
            'action' => 'pipeline.succeeded',
            'level' => 'info',
            'title' => fake()->slug(2),
            'description' => fake()->sentence(),
            'created_at' => now(),
        ];
    }
}
