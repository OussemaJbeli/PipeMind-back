<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pipeline;
use App\Models\PipelineJob;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class FailureFactory extends Factory
{
    public function definition(): array
    {
        $project = Project::factory();

        return [
            'team_id' => Team::factory(),
            'project_id' => $project,
            'pipeline_id' => Pipeline::factory(),
            'job_id' => PipelineJob::factory(),
            'status' => 'detected',
            'severity' => 'high',
            'category' => 'DATABASE',
            'subcategory' => 'ConnectionRefused',
            'stage_name' => 'test',
            'job_name' => 'backend-tests',
            'error_message' => 'SQLSTATE[HY000] [2002] Connection refused',
            'exit_code' => 1,
            'failed_at' => now(),
        ];
    }

    public function analyzed(): static
    {
        return $this->state(fn () => ['status' => 'analyzed']);
    }

    public function flaky(): static
    {
        return $this->state(fn () => ['is_flaky' => true, 'severity' => 'low']);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_type' => 'fixed',
            'time_to_resolution_seconds' => fake()->numberBetween(300, 2700),
        ]);
    }
}
