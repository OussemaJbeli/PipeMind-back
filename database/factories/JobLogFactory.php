<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PipelineJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class JobLogFactory extends Factory
{
    public function definition(): array
    {
        $excerpt = "$ php artisan test --testsuite=Integration\n"
            ."   SQLSTATE[HY000] [2002] Connection refused\n"
            .'ERROR: Job failed: exit code 1';

        return [
            'job_id' => PipelineJob::factory(),
            'pipeline_id' => fn (array $a) => PipelineJob::find($a['job_id'])?->pipeline_id,
            'project_id' => fn (array $a) => PipelineJob::with('pipeline')
                ->find($a['job_id'])?->pipeline?->project_id,
            'storage_disk' => 'logs',
            'storage_path' => 'logs/'.$this->faker->uuid.'.log',
            'size_bytes' => 4096,
            'line_count' => 120,
            'excerpt' => $excerpt,
            'excerpt_start_line' => 1,
            'excerpt_end_line' => 3,
            'error_block' => 'SQLSTATE[HY000] [2002] Connection refused',
            'is_redacted' => false,
            'redaction_count' => 0,
            'redaction_types' => [],
            'truncated' => false,
            'fetched_at' => now(),
            'processed_at' => now(),
        ];
    }
}
