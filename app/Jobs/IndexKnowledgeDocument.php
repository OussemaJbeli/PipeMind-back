<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Ai\AiGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Chunks a runbook or design document, embeds it, and makes it retrievable.
 *
 * This is what fills `knowledge_chunks` — without it the RAG layer's third
 * source is permanently empty and project documentation never reaches an
 * analysis.
 */
class IndexKnowledgeDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public int $documentId)
    {
        $this->onQueue('analysis');
    }

    /** @return array<int,object> */
    public function middleware(): array
    {
        return [new WithoutOverlapping("knowledge:{$this->documentId}")];
    }

    public function handle(AiGateway $ai): void
    {
        $document = KnowledgeDocument::withoutGlobalScopes()
            ->with('team')
            ->find($this->documentId);

        if (! $document?->team || ! filled($document->content)) {
            return;
        }

        withTeam($document->team, function () use ($document, $ai): void {
            $response = $ai->chunkEmbed($document->content, $document->title);
            $chunks = $response['chunks'] ?? [];

            if (! $chunks) {
                return;
            }

            DB::transaction(function () use ($document, $chunks, $response): void {
                // Replace rather than merge: a re-indexed document has different
                // boundaries, so old chunks would linger as orphans that still
                // match queries and cite text no longer in the document.
                KnowledgeChunk::where('document_id', $document->id)->delete();

                foreach ($chunks as $chunk) {
                    KnowledgeChunk::create([
                        'document_id' => $document->id,
                        'team_id' => $document->team_id,
                        'chunk_index' => $chunk['index'],
                        'content' => $chunk['content'],
                        'token_count' => $chunk['token_count'] ?? null,
                        'embedding' => $chunk['embedding'],
                        'model' => substr(basename((string) ($response['model'] ?? 'unknown')), 0, 120),
                    ]);
                }

                $document->forceFill([
                    'indexed_at' => now(),
                    'content_hash' => hash('sha256', $document->content),
                    'token_count' => array_sum(array_column($chunks, 'token_count')),
                ])->save();
            });
        });
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('pipemind.knowledge.index_failed', [
            'document_id' => $this->documentId,
            'reason' => $e?->getMessage(),
        ]);
    }
}
