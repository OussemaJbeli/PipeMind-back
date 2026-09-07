<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnomalyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'team_id' => fn (array $a) => Project::find($a['project_id'])?->team_id,
            'type' => 'duration',
            'severity' => 'medium',
            'metric_name' => 'job.npm-ci.duration_seconds',
            'observed_value' => 660,
            'baseline_value' => 162,
            'deviation_ratio' => 4.074,
            'z_score' => 9.2,
            // MAD, not zscore: the primary spread measure is the median absolute
            // deviation, and the stored method must say which was actually used.
            'detection_method' => 'mad',
            'title' => 'npm-ci took 4.07× longer than usual',
            'description' => 'Ran in 11m 00s against a median of 2m 42s across 47 runs in the last 30 days.',
            'possible_causes' => ['Build cache miss', 'Runner resource contention'],
            'status' => 'open',
            'detected_at' => now(),
        ];
    }

    public function flaky(): static
    {
        return $this->state(fn () => [
            'type' => 'flaky_test',
            'metric_name' => 'job.e2e-tests.flaky',
            'observed_value' => 1,
            'baseline_value' => 0,
            'deviation_ratio' => null,
            'z_score' => null,
            'detection_method' => 'rule',
            'title' => 'e2e-tests both passed and failed on the same commit',
        ]);
    }
}
