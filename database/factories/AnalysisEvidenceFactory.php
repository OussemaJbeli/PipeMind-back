<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Analysis;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnalysisEvidenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'type' => 'log_line',
            'content' => 'SQLSTATE[HY000] [2002] Connection refused',
            // Evidence without a source_ref looks checkable but is not.
            'source_ref' => 'job_logs#L1294',
            'line_number' => 1294,
            'weight' => 0.95,
            'position' => 0,
        ];
    }
}
