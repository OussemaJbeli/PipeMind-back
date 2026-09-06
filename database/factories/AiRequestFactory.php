<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiRequestFactory extends Factory
{
    public function definition(): array
    {
        $prompt = $this->faker->numberBetween(300, 1200);
        $completion = $this->faker->numberBetween(80, 400);

        return [
            'team_id' => Team::factory(),
            'operation' => 'analyze',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'cost_usd' => 0.000412,
            'latency_ms' => $this->faker->numberBetween(800, 5000),
            'cache_hit' => false,
            'status' => 'success',
        ];
    }

    /** The row that proves the cache works: zero tokens, zero cost. */
    public function cacheHit(): static
    {
        return $this->state([
            'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0,
            'cost_usd' => 0, 'latency_ms' => 0, 'cache_hit' => true,
        ]);
    }
}
