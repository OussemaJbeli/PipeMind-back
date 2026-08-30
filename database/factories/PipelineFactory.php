<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class PipelineFactory extends Factory
{
    public function definition(): array
    {
        $duration = fake()->numberBetween(45, 480);
        $started = fake()->dateTimeBetween('-30 days', 'now');
        $sha = fake()->sha1();

        return [
            'project_id' => Project::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(10000, 999999),
            'iid' => fake()->unique()->numberBetween(1, 9999),
            'provider' => 'gitlab',
            'status' => 'success',
            'source' => fake()->randomElement(['push', 'merge_request', 'schedule']),
            'ref' => fake()->randomElement(['main', 'feature/payment', 'feature/auth', 'bugfix/db-conn']),
            'commit_sha' => $sha,
            'commit_short_sha' => substr($sha, 0, 8),
            'commit_message' => fake()->sentence(5),
            'commit_author_name' => fake()->name(),
            'commit_author_email' => fake()->safeEmail(),
            'started_at' => $started,
            'finished_at' => (clone $started)->modify("+{$duration} seconds"),
            'duration_seconds' => $duration,
            'queue_seconds' => fake()->numberBetween(0, 30),
            'jobs_total' => 6,
            'jobs_succeeded' => 6,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'has_failure' => true,
            'jobs_failed' => 1,
            'jobs_succeeded' => 5,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => 'running',
            'finished_at' => null,
            'duration_seconds' => null,
            'jobs_succeeded' => 3,
        ]);
    }
}
