<?php

declare(strict_types=1);

use App\Jobs\IndexKnowledgeDocument;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\AiFakes;

it('chunks, embeds and stores a document', function () {
    Http::fake(['*' => AiFakes::router()]);
    $user = User::factory()->withTeam()->create();

    $document = KnowledgeDocument::factory()->create(['team_id' => $user->current_team_id]);

    IndexKnowledgeDocument::dispatchSync($document->id);

    $chunks = KnowledgeChunk::where('document_id', $document->id)->orderBy('chunk_index')->get();

    expect($chunks)->toHaveCount(3)
        ->and($chunks->first()->embedding)->toHaveCount(384)
        ->and($chunks->first()->model)->toBe('all-MiniLM-L6-v2')
        ->and($chunks->pluck('chunk_index')->all())->toBe([0, 1, 2]);

    expect($document->fresh()->indexed_at)->not->toBeNull();
});

it('replaces old chunks instead of leaving orphans behind', function () {
    // A second Http::fake() appends to the existing stubs rather than replacing
    // them, so the shrinking response is expressed as one stateful closure.
    $call = 0;
    Http::fake(function () use (&$call) {
        $call++;

        return Http::response(AiFakes::chunkEmbed($call === 1 ? 3 : 2));
    });

    $user = User::factory()->withTeam()->create();
    $document = KnowledgeDocument::factory()->create(['team_id' => $user->current_team_id]);

    IndexKnowledgeDocument::dispatchSync($document->id);
    expect(KnowledgeChunk::where('document_id', $document->id)->count())->toBe(3);

    // A re-indexed document has different boundaries, so stale chunks would
    // still match queries and cite text the document no longer contains.
    IndexKnowledgeDocument::dispatchSync($document->id);

    expect(KnowledgeChunk::where('document_id', $document->id)->count())->toBe(2);
});

it('does nothing for an empty document', function () {
    Http::fake(['*' => AiFakes::router()]);
    $user = User::factory()->withTeam()->create();
    $document = KnowledgeDocument::factory()->create([
        'team_id' => $user->current_team_id,
        'content' => '',
    ]);

    IndexKnowledgeDocument::dispatchSync($document->id);

    expect(KnowledgeChunk::count())->toBe(0);
    Http::assertNothingSent();
});
