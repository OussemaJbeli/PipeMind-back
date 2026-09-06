<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Pipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommitChangeFactory extends Factory
{
    public function definition(): array
    {
        $path = $this->faker->randomElement([
            'app/Services/PaymentService.php',
            'src/components/Header.vue',
            'tests/Unit/PriceTest.php',
        ]);

        return [
            'pipeline_id' => Pipeline::factory(),
            'project_id' => fn (array $a) => Pipeline::find($a['pipeline_id'])?->project_id
                ?? Pipeline::factory()->create()->project_id,
            'file_path' => $path,
            'change_type' => 'modified',
            'additions' => $this->faker->numberBetween(1, 80),
            'deletions' => $this->faker->numberBetween(0, 20),
            'is_config' => false,
            'is_dependency' => false,
        ];
    }

    /** Config and dependency changes explain far more failures than source edits. */
    public function config(): static
    {
        return $this->state(['file_path' => 'docker-compose.yml', 'is_config' => true]);
    }

    public function dependency(): static
    {
        return $this->state(['file_path' => 'package-lock.json', 'is_dependency' => true]);
    }
}
