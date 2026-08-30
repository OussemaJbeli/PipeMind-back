<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

class PipelineJobFactory extends Factory
{
    public function definition(): array
    {
        $duration = fake()->numberBetween(5, 200);

        return [
            'pipeline_id' => Pipeline::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'name' => fake()->randomElement(['checkout', 'npm-ci', 'eslint', 'backend-tests', 'build', 'deploy']),
            'stage_name' => 'test',
            'status' => 'success',
            'duration_seconds' => $duration,
            'queue_seconds' => fake()->numberBetween(0, 20),
            'peak_memory_mb' => fake()->numberBetween(180, 900),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => 'failed', 'exit_code' => 1]);
    }
}
