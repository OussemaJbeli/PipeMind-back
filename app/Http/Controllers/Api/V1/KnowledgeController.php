<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\IndexKnowledgeDocument;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Runbooks and design notes that an analysis can quote.
 *
 * The retrieval half has existed since roadmap 09 — chunking, embedding, and a
 * pgvector query over `knowledge_chunks`. Nothing populated it, so RAG's third
 * source was permanently empty and a documented answer could never reach an
 * analysis. This is the missing half.
 */
class KnowledgeController extends Controller
{
    private const TYPES = ['runbook', 'readme', 'ci_config', 'incident', 'resolution', 'doc', 'faq'];

    /**
     * Documents visible to a project.
     *
     * Includes team-wide documents (`project_id IS NULL`) alongside the
     * project's own, because that is exactly what retrieval sees — a list that
     * showed only project-scoped rows would misrepresent what the model reads.
     */
    public function index(Project $project): array
    {
        $documents = KnowledgeDocument::query()
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $project->id))
            ->withCount('chunks')
            ->with('creator:id,name')
            ->reorder('title')
            ->get();

        return ['data' => $documents->map(fn (KnowledgeDocument $doc) => $this->present($doc))->all()];
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::TYPES)],
            'content' => ['required', 'string', 'max:400000'],
            'source_url' => ['nullable', 'url', 'max:500'],
            // Team-wide documents apply to every project. Opt-in rather than
            // default: a runbook written for one service is usually wrong advice
            // for another, and retrieval ranks project-scoped rows above
            // team-wide ones precisely because of that.
            'team_wide' => ['sometimes', 'boolean'],
        ]);

        $document = KnowledgeDocument::create([
            'project_id' => $request->boolean('team_wide') ? null : $project->id,
            'type' => $data['type'],
            'title' => $data['title'],
            'content' => $data['content'],
            'source_url' => $data['source_url'] ?? null,
            'content_hash' => hash('sha256', $data['content']),
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        // Queued, not inline: chunking a long runbook means an embedding call per
        // chunk, and a request that waits for that times out on anything large.
        IndexKnowledgeDocument::dispatch($document->id);

        activity_log($project, 'knowledge.added', 'info', $project->name,
            "Added {$data['type']}: {$data['title']}", $document);

        return response()->json(['data' => $this->present($document)], 201);
    }

    public function update(Request $request, KnowledgeDocument $document): array
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(self::TYPES)],
            'content' => ['sometimes', 'string', 'max:400000'],
            'source_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $contentChanged = isset($data['content'])
            && hash('sha256', $data['content']) !== $document->content_hash;

        $document->fill($data);

        if ($contentChanged) {
            // version, so a stale answer can be traced to the text that produced
            // it rather than to whatever the document says today.
            $document->content_hash = hash('sha256', $data['content']);
            $document->version = $document->version + 1;
            $document->indexed_at = null;
        }

        $document->save();

        // Only when the text actually changed. Re-embedding on a title edit
        // would burn the embedding budget for nothing.
        if ($contentChanged) {
            IndexKnowledgeDocument::dispatch($document->id);
        }

        return ['data' => $this->present($document->fresh())];
    }

    public function destroy(KnowledgeDocument $document): JsonResponse
    {
        // Hard delete, unlike projects: a document has no history worth keeping,
        // and leaving a deactivated row in place would keep answering queries
        // from text the user believes they removed. The chunks cascade.
        $document->delete();

        return response()->json(status: 204);
    }

    /** Re-chunks and re-embeds. For a stale index or a changed embedding model. */
    public function reindex(KnowledgeDocument $document): array
    {
        $document->forceFill(['indexed_at' => null])->save();

        IndexKnowledgeDocument::dispatch($document->id);

        return ['data' => $this->present($document->fresh())];
    }

    /** @return array<string,mixed> */
    private function present(KnowledgeDocument $document): array
    {
        return [
            'uuid' => $document->uuid,
            'type' => $document->type,
            'title' => $document->title,
            'source_url' => $document->source_url,
            'token_count' => $document->token_count,
            'version' => (int) $document->version,
            'is_active' => (bool) $document->is_active,
            'team_wide' => $document->project_id === null,
            'chunks_count' => (int) ($document->chunks_count ?? $document->chunks()->count()),
            // Null means the index job has not finished — or failed. The UI shows
            // "indexing…" rather than implying the document is already
            // retrievable, because an unindexed document reaches no analysis.
            'indexed_at' => $document->indexed_at?->toIso8601String(),
            'created_by' => $document->creator?->name,
            'created_at' => $document->created_at?->toIso8601String(),
            'excerpt' => Str::limit($document->content, 240),
        ];
    }

    /** Full content, for the editor. Excluded from the list to keep it small. */
    public function show(KnowledgeDocument $document): array
    {
        return ['data' => [
            ...$this->present($document),
            'content' => $document->content,
        ]];
    }
}
