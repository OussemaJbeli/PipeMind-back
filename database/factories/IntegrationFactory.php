<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class IntegrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'provider' => 'gitlab',
            'name' => 'GitLab',
            'base_url' => 'https://gitlab.com',
            'credentials' => ['token' => 'glpat-'.Str::random(20)],
            'webhook_secret' => Str::random(48),
            'scopes' => ['api'],
            'status' => 'active',
            'last_verified_at' => now(),
            'last_event_at' => now()->subMinutes(3),
        ];
    }

    public function gitlab(): static
    {
        return $this->state(fn () => ['provider' => 'gitlab', 'name' => 'GitLab']);
    }

    public function github(): static
    {
        return $this->state(fn () => ['provider' => 'github', 'name' => 'GitHub Actions', 'base_url' => 'https://api.github.com']);
    }
}
