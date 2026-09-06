<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Failure;
use Illuminate\Database\Eloquent\Factories\Factory;

class FailureEmbeddingFactory extends Factory
{
    public function definition(): array
    {
        // A deterministic unit vector. Random values would make every similarity
        // assertion in the suite flaky for no benefit.
        $vector = array_fill(0, 384, 0.0);
        $vector[0] = 1.0;

        return [
            'failure_id' => Failure::factory(),
            'team_id' => fn (array $a) => Failure::find($a['failure_id'])?->team_id,
            'embedding' => $vector,
            'model' => 'all-MiniLM-L6-v2',
            'dimensions' => 384,
            'source_text' => 'SQLSTATE[HY000] [2002] Connection refused | category: DATABASE',
        ];
    }
}
