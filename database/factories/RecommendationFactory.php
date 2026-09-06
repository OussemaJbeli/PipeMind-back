<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Analysis;
use App\Models\Failure;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecommendationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'failure_id' => Failure::factory(),
            'title' => 'Add a database readiness healthcheck',
            'description' => 'Restore the healthcheck-based depends_on so the test job waits for Postgres.',
            'rationale' => 'An identical signature was resolved this way before.',
            'action_type' => 'update_config',
            // Mirrors the AI service: risk follows action_type, never the model.
            'risk' => 'high',
            'confidence' => 0.88,
            'affected_files' => ['docker-compose.yml'],
            'position' => 0,
            'status' => 'proposed',
        ];
    }
}
