<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class RemediationPolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'project_id' => null,
            'action_type' => 'retry_job',
            'mode' => 'auto',
            'max_risk' => 'low',
            'min_confidence' => 0.850,
            'max_per_day' => 5,
            'allowed_branches' => ['*'],
            'blocked_branches' => ['main', 'master', 'production'],
            'enabled' => true,
        ];
    }

    public function forbidden(): static
    {
        return $this->state(fn () => ['mode' => 'forbidden']);
    }

    public function approval(): static
    {
        return $this->state(fn () => ['mode' => 'approval']);
    }

    /** Nothing blocked, everything allowed: isolates the rule under test. */
    public function anyBranch(): static
    {
        return $this->state(fn () => ['allowed_branches' => ['*'], 'blocked_branches' => []]);
    }
}
