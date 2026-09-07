<?php

declare(strict_types=1);

use App\Models\TeamInvitation;
use App\Models\User;

it('lists members with the flags the UI needs before rendering controls', function () {
    $owner = User::factory()->withTeam()->create();
    $member = User::factory()->create();
    $owner->currentTeam->users()->attach($member, ['role' => 'member', 'joined_at' => now()]);

    $data = $this->actingAs($owner)->getJson('/api/v1/members')->assertOk()->json('data');

    expect($data)->toHaveCount(2);

    $ownerRow = collect($data)->firstWhere('uuid', $owner->uuid);

    // The owner cannot be demoted or removed; the UI must know that before
    // drawing the buttons, not after a rejected request.
    expect($ownerRow['is_owner'])->toBeTrue()
        ->and($ownerRow['is_you'])->toBeTrue()
        ->and(collect($data)->firstWhere('uuid', $member->uuid)['is_owner'])->toBeFalse();
});

it('changes a role', function () {
    $owner = User::factory()->withTeam()->create();
    $member = User::factory()->create();
    $owner->currentTeam->users()->attach($member, ['role' => 'viewer', 'joined_at' => now()]);

    $this->actingAs($owner)->putJson("/api/v1/members/{$member->uuid}", ['role' => 'admin'])
        ->assertOk();

    expect($owner->currentTeam->users()->find($member->id)->pivot->role)->toBe('admin');
});

it('refuses to demote the owner', function () {
    $owner = User::factory()->withTeam()->create();

    // A team with no owner has nobody who can delete it or change billing.
    $this->actingAs($owner)->putJson("/api/v1/members/{$owner->uuid}", ['role' => 'member'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'OWNER_ROLE_IMMUTABLE');
});

it('refuses to remove the owner', function () {
    $owner = User::factory()->withTeam()->create();

    $this->actingAs($owner)->deleteJson("/api/v1/members/{$owner->uuid}")
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'OWNER_IMMUTABLE');
});

it('moves a removed member off the team they can no longer see', function () {
    $owner = User::factory()->withTeam()->create();
    $other = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $team->users()->attach($other, ['role' => 'member', 'joined_at' => now()]);
    $other->forceFill(['current_team_id' => $team->id])->save();

    $this->actingAs($owner)->deleteJson("/api/v1/members/{$other->uuid}")->assertOk();

    // Otherwise they land on an empty workspace with no way out of it.
    expect($other->fresh()->current_team_id)->not->toBe($team->id)
        ->and($other->fresh()->current_team_id)->not->toBeNull();
});

it('404s for a user who belongs to a different workspace', function () {
    $owner = User::factory()->withTeam()->create();
    $stranger = User::factory()->withTeam()->create();

    // Users are legitimately multi-team so the binding cannot be team-scoped.
    // updateExistingPivot affects zero rows for a non-member, which without an
    // explicit lookup returns a cheerful 200 having changed nothing.
    $this->actingAs($owner)->putJson("/api/v1/members/{$stranger->uuid}", ['role' => 'admin'])
        ->assertNotFound();

    $this->actingAs($owner)->deleteJson("/api/v1/members/{$stranger->uuid}")
        ->assertNotFound();
});

it('invites someone and returns a usable link', function () {
    $owner = User::factory()->withTeam()->create();

    $data = $this->actingAs($owner)->postJson('/api/v1/invitations', [
        'email' => 'newcomer@example.test',
        'role' => 'member',
    ])->assertOk()->json('data');

    // Mail delivery lands in roadmaps/20; until then the link is how anybody
    // actually joins, so it has to come back from this call.
    expect($data['accept_url'])->toContain($data['email'] === 'newcomer@example.test' ? 'invitations/' : '')
        ->and($data['accept_url'])->toStartWith(config('pipemind.frontend_url'));
});

it('re-inviting refreshes the invitation instead of failing', function () {
    $owner = User::factory()->withTeam()->create();

    $first = $this->actingAs($owner)->postJson('/api/v1/invitations',
        ['email' => 'again@example.test', 'role' => 'viewer'])->json('data');

    $second = $this->actingAs($owner)->postJson('/api/v1/invitations',
        ['email' => 'again@example.test', 'role' => 'admin'])->assertOk()->json('data');

    // UNIQUE (team_id, email) means a second invite is a re-invite.
    expect(TeamInvitation::count())->toBe(1)
        ->and($second['role'])->toBe('admin')
        ->and($second['uuid'])->toBe($first['uuid']);
});

it('refuses to invite an existing member', function () {
    $owner = User::factory()->withTeam()->create();

    $this->actingAs($owner)->postJson('/api/v1/invitations',
        ['email' => $owner->email, 'role' => 'member'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'ALREADY_A_MEMBER');
});

it('never lets one workspace revoke another\'s invitation', function () {
    $mine = User::factory()->withTeam()->create();
    $theirs = User::factory()->withTeam()->create();

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $theirs->current_team_id,
        'invited_by' => $theirs->id,
    ]);

    $this->actingAs($mine)->deleteJson("/api/v1/invitations/{$invitation->uuid}")
        ->assertNotFound();

    expect(TeamInvitation::withoutGlobalScopes()->count())->toBe(1);
});

describe('accepting an invitation', function () {
    it('joins the team and switches to it', function () {
        $inviter = User::factory()->withTeam()->create();
        $invitee = User::factory()->create();

        $invitation = TeamInvitation::factory()->create([
            'team_id' => $inviter->current_team_id,
            'email' => $invitee->email,
            'role' => 'member',
            'invited_by' => $inviter->id,
        ]);

        $this->actingAs($invitee)->postJson("/api/v1/invitations/{$invitation->token}/accept")
            ->assertOk()
            ->assertJsonPath('data.role', 'member');

        expect($invitee->fresh()->current_team_id)->toBe($inviter->current_team_id)
            ->and($invitation->fresh()->accepted_at)->not->toBeNull();
    });

    it('refuses a link forwarded to a different account', function () {
        $inviter = User::factory()->withTeam()->create();
        $invitee = User::factory()->create();

        $invitation = TeamInvitation::factory()->create([
            'team_id' => $inviter->current_team_id,
            'email' => 'someone.else@example.test',
            'invited_by' => $inviter->id,
        ]);

        // The invitation names an address. Honouring it for whoever happens to
        // click would let a forwarded link grant access to the wrong person.
        $this->actingAs($invitee)->postJson("/api/v1/invitations/{$invitation->token}/accept")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'INVITATION_WRONG_EMAIL');
    });

    it('refuses an expired invitation', function () {
        $inviter = User::factory()->withTeam()->create();
        $invitee = User::factory()->create();

        $invitation = TeamInvitation::factory()->expired()->create([
            'team_id' => $inviter->current_team_id,
            'email' => $invitee->email,
            'invited_by' => $inviter->id,
        ]);

        $this->actingAs($invitee)->postJson("/api/v1/invitations/{$invitation->token}/accept")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVITATION_INVALID');
    });
});
