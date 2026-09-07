<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Jobs\IndexKnowledgeDocument;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function knowledgeProject(User $user): Project
{
    return Project::factory()->create(['team_id' => $user->current_team_id]);
}

it('lists project documents alongside team-wide ones', function () {
    $user = User::factory()->withTeam()->create();
    $project = knowledgeProject($user);
    $other = knowledgeProject($user);

    $mine = KnowledgeDocument::factory()->create([
        'team_id' => $user->current_team_id, 'project_id' => $project->id, 'title' => 'This project',
    ]);
    $teamWide = KnowledgeDocument::factory()->create([
        'team_id' => $user->current_team_id, 'project_id' => null, 'title' => 'Team standard',
    ]);
    KnowledgeDocument::factory()->create([
        'team_id' => $user->current_team_id, 'project_id' => $other->id, 'title' => 'Someone else',
    ]);

    $titles = collect($this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->slug}/knowledge")
        ->assertOk()->json('data'))->pluck('title');

    // Team-wide documents are retrievable during this project's analyses, so
    // hiding them here would misrepresent what the AI can actually draw on.
    expect($titles)->toContain($mine->title, $teamWide->title)
        ->and($titles)->not->toContain('Someone else');
});

it('never lists another team\'s documents', function () {
    $user = User::factory()->withTeam()->create();
    $project = knowledgeProject($user);

    $intruder = KnowledgeDocument::factory()->create([
        'team_id' => Team::factory()->create()->id,
        'project_id' => null,
        'title' => 'Other team runbook',
    ]);

    $titles = collect($this->actingAs($user)
        ->getJson("/api/v1/projects/{$project->slug}/knowledge")
        ->assertOk()->json('data'))->pluck('title');

    expect($titles)->not->toContain($intruder->title);
});

it('queues indexing when a document is created', function () {
    Queue::fake();
    $user = User::factory()->withTeam()->create();
    $project = knowledgeProject($user);

    $data = $this->actingAs($user)->postJson("/api/v1/projects/{$project->slug}/knowledge", [
        'type' => 'runbook',
        'title' => 'Connection refused runbook',
        'content' => str_repeat('The postgres service needs a healthcheck. ', 20),
    ])->assertCreated()->json('data');

    // Unindexed means unretrievable: the UI must not imply otherwise.
    expect($data['indexed_at'])->toBeNull();
    Queue::assertPushed(IndexKnowledgeDocument::class);
});

it('reindexes only when the content actually changed', function () {
    Queue::fake();
    $user = User::factory()->withTeam()->create();
    $document = KnowledgeDocument::factory()->create([
        'team_id' => $user->current_team_id,
        'project_id' => knowledgeProject($user)->id,
        'indexed_at' => now(),
    ]);

    // A title fix must not throw away a good index and blank the document for
    // however long the queue takes to catch up.
    $this->actingAs($user)->putJson("/api/v1/knowledge/{$document->uuid}", [
        'title' => 'Renamed, same content',
        'content' => $document->content,
    ])->assertOk();

    Queue::assertNothingPushed();
    expect($document->fresh()->indexed_at)->not->toBeNull()
        ->and($document->fresh()->version)->toBe(1);

    $this->actingAs($user)->putJson("/api/v1/knowledge/{$document->uuid}", [
        'title' => 'Renamed, same content',
        'content' => 'Genuinely different text that must be embedded again.',
    ])->assertOk();

    Queue::assertPushed(IndexKnowledgeDocument::class);
    expect($document->fresh()->indexed_at)->toBeNull()
        ->and($document->fresh()->version)->toBe(2);
});

it('removes chunks when a document is deleted', function () {
    $user = User::factory()->withTeam()->create();
    $document = KnowledgeDocument::factory()->create([
        'team_id' => $user->current_team_id,
        'project_id' => knowledgeProject($user)->id,
    ]);
    KnowledgeChunk::factory()->count(3)
        // chunk_index is unique per document, so the factory's fixed default
        // collides the moment more than one chunk is created.
        ->sequence(fn ($sequence) => ['chunk_index' => $sequence->index])
        ->create([
            'team_id' => $user->current_team_id,
            'document_id' => $document->id,
        ]);

    $this->actingAs($user)->deleteJson("/api/v1/knowledge/{$document->uuid}")->assertNoContent();

    // Orphaned chunks would keep matching queries and cite text that no longer
    // exists anywhere — a citation the user cannot check is worse than none.
    expect(KnowledgeChunk::where('document_id', $document->id)->count())->toBe(0)
        ->and(KnowledgeDocument::find($document->id))->toBeNull();
});

it('refuses to touch a document belonging to another team', function () {
    $user = User::factory()->withTeam()->create();
    $intruder = KnowledgeDocument::factory()->create([
        'team_id' => Team::factory()->create()->id,
    ]);

    $this->actingAs($user)->getJson("/api/v1/knowledge/{$intruder->uuid}")->assertNotFound();
    $this->actingAs($user)->putJson("/api/v1/knowledge/{$intruder->uuid}", [
        'title' => 'Hijacked', 'content' => 'x',
    ])->assertNotFound();
    $this->actingAs($user)->deleteJson("/api/v1/knowledge/{$intruder->uuid}")->assertNotFound();

    expect($intruder->fresh()->title)->not->toBe('Hijacked');
});

it('does not let a viewer write knowledge', function () {
    $user = User::factory()->withTeam(TeamRole::VIEWER)->create();
    $project = knowledgeProject($user);

    $this->actingAs($user)->postJson("/api/v1/projects/{$project->slug}/knowledge", [
        'type' => 'runbook', 'title' => 'Nope', 'content' => str_repeat('x ', 30),
    ])->assertForbidden();

    // Reading is a viewer's whole job, so it must still work.
    $this->actingAs($user)->getJson("/api/v1/projects/{$project->slug}/knowledge")->assertOk();
});
