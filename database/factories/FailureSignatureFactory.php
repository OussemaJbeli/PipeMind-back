<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class FailureSignatureFactory extends Factory
{
    public function definition(): array
    {
        $error = 'SQLSTATE[HY000] [2002] Connection refused';

        return [
            'team_id' => Team::factory(),
            'hash' => hash('sha256', $error.fake()->unique()->randomNumber(6)),
            'normalized_error' => 'sqlstate[hy000] [<num>] connection refused',
            'sample_error' => $error,
            'category' => 'DATABASE',
            'subcategory' => 'ConnectionRefused',
            'occurrence_count' => 1,
            'first_seen_at' => now()->subDays(3),
            'last_seen_at' => now(),
        ];
    }

    public function known(): static
    {
        return $this->state(fn () => [
            'is_known' => true,
            'known_root_cause' => 'Database container had not finished starting.',
            'known_resolution' => 'Added a healthcheck-based depends_on condition.',
            'resolution_confirmed_at' => now()->subDays(30),
        ]);
    }
}
