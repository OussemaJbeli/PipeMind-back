<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeDocumentFactory extends Factory
{
    public function definition(): array
    {
        $content = collect(range(1, 8))
            ->map(fn ($i) => "Step {$i}. ".$this->faker->paragraph(6))
            ->implode("\n\n");

        return [
            'team_id' => Team::factory(),
            'type' => 'runbook',
            'title' => 'Database recovery runbook',
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'version' => 1,
            'is_active' => true,
        ];
    }
}
