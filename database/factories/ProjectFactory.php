<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'team_id' => Team::factory(),
            'integration_id' => Integration::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'external_id' => (string) fake()->unique()->numberBetween(1000, 9999),
            'external_path' => 'OussemaJbeli/'.$name,
            'repository_url' => "https://github.com/OussemaJbeli/{$name}.git",
            'web_url' => "https://github.com/OussemaJbeli/{$name}",
            'default_branch' => 'main',
            'icon' => 'code',
            'color' => '#6366F1',
            'tech_stack' => ['Laravel', 'Docker', 'GitHub'],
            'is_active' => true,
            'auto_analyze' => true,
        ];
    }
}
