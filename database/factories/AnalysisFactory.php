<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Failure;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnalysisFactory extends Factory
{
    public function definition(): array
    {
        return [
            'failure_id' => Failure::factory(),
            'team_id' => Team::factory(),
            'status' => 'completed',
            'contract_version' => 'v1',
            'ai_service_version' => '0.1.0',
            'category' => 'DATABASE',
            'subcategory' => 'ConnectionRefused',
            'severity' => 'high',
            'confidence' => fake()->randomFloat(3, 0.72, 0.96),
            'summary' => 'Database was unavailable when integration tests started.',
            'root_cause' => 'The database container had not finished its startup sequence before the test job began connecting.',
            'classification_source' => 'hybrid',
            'classification_confidence' => 0.94,
            'used_rag' => true,
            'similar_failures_count' => 2,
            'model_provider' => 'gemini',
            'model_name' => 'gemini-2.0-flash',
            'prompt_tokens' => fake()->numberBetween(1500, 3200),
            'completion_tokens' => fake()->numberBetween(220, 480),
            'cost_usd' => fake()->randomFloat(6, 0.0002, 0.0012),
            'latency_ms' => fake()->numberBetween(2100, 6800),
            'started_at' => now()->subSeconds(6),
            'completed_at' => now(),
        ];
    }

    public function cached(): static
    {
        return $this->state(fn () => [
            'cache_hit' => true, 'cost_usd' => 0, 'latency_ms' => fake()->numberBetween(12, 80),
        ]);
    }
}
