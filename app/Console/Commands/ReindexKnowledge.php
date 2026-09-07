<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\IndexKnowledgeDocument;
use App\Models\KnowledgeDocument;
use Illuminate\Console\Command;

/**
 * Re-chunks and re-embeds knowledge documents.
 *
 * Needed because chunk boundaries are not a formatting detail: they are part of
 * what was embedded. When the chunk size changes, every previously indexed
 * document keeps its old boundaries and stays wrong — and wrong here is silent,
 * because a stale chunk still matches queries, just badly. Documents indexed
 * before 2026-09-07 were built at 400 tokens, past the embedder's 256
 * word-piece window, so roughly a third of each one is embedded as if it were
 * not there. See PipeMind-data/experiments/knowledge-retrieval.md.
 */
class ReindexKnowledge extends Command
{
    protected $signature = 'pipemind:reindex-knowledge
                            {--project= : Limit to one project slug}
                            {--stale : Only documents whose chunks predate the current chunk size}
                            {--dry-run : List what would be queued and exit}';

    protected $description = 'Re-chunk and re-embed knowledge documents';

    /**
     * Chunks larger than this were produced by the pre-2026-09-07 chunker and
     * are truncated by the embedder. Compared against token_count, which is the
     * chunker's own estimate — the same units it targets.
     */
    private const STALE_CHUNK_TOKENS = 200;

    public function handle(): int
    {
        $documents = KnowledgeDocument::withoutGlobalScopes()
            ->with('team')
            ->when($this->option('project'), fn ($q) => $q->whereHas(
                'project', fn ($p) => $p->where('slug', $this->option('project'))
            ))
            ->when($this->option('stale'), fn ($q) => $q->whereHas(
                'chunks', fn ($c) => $c->where('token_count', '>', self::STALE_CHUNK_TOKENS)
            ))
            ->where('is_active', true)
            ->get();

        if ($documents->isEmpty()) {
            $this->info('Nothing to reindex.');

            return self::SUCCESS;
        }

        foreach ($documents as $document) {
            $this->line(sprintf(
                '  %s  %s (%d chunks)',
                $this->option('dry-run') ? '[dry-run]' : 'queued   ',
                $document->title,
                $document->chunks()->count(),
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            // A document with no team cannot be scoped, and IndexKnowledgeDocument
            // needs one to bind. Skipping beats dispatching a job that returns
            // silently.
            if (! $document->team) {
                $this->warn("  skipped: {$document->title} has no team");

                continue;
            }

            // indexed_at is cleared so the UI shows the document as pending
            // rather than claiming a freshness it does not have yet.
            $document->forceFill(['indexed_at' => null])->saveQuietly();

            IndexKnowledgeDocument::dispatch($document->id);
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d document(s).',
            $this->option('dry-run') ? 'Would reindex' : 'Queued',
            $documents->count(),
        ));

        return self::SUCCESS;
    }
}
