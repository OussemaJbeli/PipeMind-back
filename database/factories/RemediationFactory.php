<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Failure;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class RemediationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'project_id' => Project::factory(),
            'failure_id' => Failure::factory(),
            'action_type' => 'retry_job',
            'risk' => 'low',
            'policy_decision' => 'requires_approval',
            'policy_reason' => 'This action always requires approval.',
            'status' => 'pending_approval',
            'expires_at' => now()->addDay(),
        ];
    }

    public function pendingApproval(): static
    {
        return $this->state(fn () => ['status' => 'pending_approval', 'expires_at' => now()->addDay()]);
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => 'succeeded', 'policy_decision' => 'auto_allowed',
            'executed_at' => now()->subMinutes(4), 'completed_at' => now()->subMinutes(2),
            'outcome_success' => true,
        ]);
    }
}
