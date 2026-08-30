<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => 'Gemini Flash',
            'provider' => 'gemini',
            'model' => 'gemini-2.0-flash',
            'is_default' => true,
            'is_local' => false,
            'max_tokens' => 4096,
            'temperature' => 0.20,
            // Placeholder rates: fill from the provider's current pricing page.
            'input_cost_per_1k' => 0.000075,
            'output_cost_per_1k' => 0.000300,
            'status' => 'active',
            'last_tested_at' => now(),
        ];
    }

    public function ollama(): static
    {
        return $this->state(fn () => [
            'name' => 'Ollama (local)', 'provider' => 'ollama', 'model' => 'qwen2.5-coder:7b',
            'base_url' => 'http://localhost:11434', 'is_local' => true, 'is_default' => false,
            'input_cost_per_1k' => 0, 'output_cost_per_1k' => 0,
        ]);
    }

    public function stub(): static
    {
        return $this->state(fn () => [
            'name' => 'Stub (no key)', 'provider' => 'stub', 'model' => 'stub-v1',
            'is_local' => true, 'input_cost_per_1k' => 0, 'output_cost_per_1k' => 0,
        ]);
    }
}
