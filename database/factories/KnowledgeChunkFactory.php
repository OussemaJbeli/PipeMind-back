<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KnowledgeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeChunkFactory extends Factory
{
    public function definition(): array
    {
        $vector = array_fill(0, 384, 0.0);
        $vector[0] = 1.0;

        return [
            'document_id' => KnowledgeDocument::factory(),
            'team_id' => fn (array $a) => KnowledgeDocument::find($a['document_id'])?->team_id,
            'chunk_index' => 0,
            'content' => 'Restart the database container and wait for the healthcheck.',
            'token_count' => 12,
            'embedding' => $vector,
            'model' => 'all-MiniLM-L6-v2',
        ];
    }
}
