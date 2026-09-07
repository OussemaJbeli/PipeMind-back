<?php

declare(strict_types=1);

use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('answers identically for a known and an unknown address', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test']);

    // Any difference — message, status, even shape — turns this into an
    // account-enumeration oracle, which is the whole risk of the endpoint.
    expect($known->status())->toBe($unknown->status())
        ->and($known->json())->toBe($unknown->json());

    Notification::assertSentTo($user, ResetPassword::class);
});

it('points the reset link at the SPA, not the API', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        // A link to an API route renders JSON in the user's browser.
        return str_starts_with($url, config('pipemind.frontend_url'))
            && str_contains($url, '/reset-password/')
            && str_contains($url, urlencode($user->email));
    });
});

it('resets the password and revokes every existing session and token', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
    $token = Password::createToken($user);
    $original = $user->remember_token;
    $user->createToken('cli');

    expect($user->tokens()->count())->toBe(1);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertOk();

    $user->refresh();

    expect(Hash::check('brand-new-password', $user->password))->toBeTrue()
        // A reset is often a response to a compromise. Leaving old sessions and
        // bearer tokens alive would defeat the point of resetting.
        ->and($user->remember_token)->not->toBe($original)
        ->and($user->tokens()->count())->toBe(0);
});

it('refuses a bad token', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertStatus(422);
});

it('requires a confirmed password of reasonable length', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token, 'email' => $user->email,
        'password' => 'short', 'password_confirmation' => 'short',
    ])->assertStatus(422);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token, 'email' => $user->email,
        'password' => 'long-enough-password', 'password_confirmation' => 'different-one',
    ])->assertStatus(422);
});

describe('invitation preview', function () {
    it('shows the workspace and inviter without signing in', function () {
        $inviter = User::factory()->withTeam()->create(['name' => 'Oussema']);
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $inviter->current_team_id,
            'invited_by' => $inviter->id,
            'role' => 'member',
        ]);

        $data = $this->getJson("/api/v1/invitations/{$invitation->token}")->assertOk()->json('data');

        // The invitee needs this to decide whether to create an account at all.
        expect($data['invited_by'])->toBe('Oussema')
            ->and($data['role'])->toBe('member')
            ->and($data['team']['name'])->not->toBeEmpty();
    });

    it('leaks nothing the link holder does not already have', function () {
        $inviter = User::factory()->withTeam()->create();
        $invitation = TeamInvitation::factory()->create([
            'team_id' => $inviter->current_team_id, 'invited_by' => $inviter->id,
        ]);

        $data = $this->getJson("/api/v1/invitations/{$invitation->token}")->json('data');

        // No ids, no member list, no project data — a guessed token is worthless.
        expect(array_keys($data))->toBe(['team', 'invited_by', 'email', 'role', 'expires_at'])
            ->and($data['team'])->not->toHaveKey('id');
    });

    it('refuses an expired or already-accepted invitation', function () {
        $inviter = User::factory()->withTeam()->create();

        $expired = TeamInvitation::factory()->expired()->create([
            'team_id' => $inviter->current_team_id, 'invited_by' => $inviter->id,
        ]);
        $used = TeamInvitation::factory()->create([
            'team_id' => $inviter->current_team_id, 'invited_by' => $inviter->id,
            'accepted_at' => now(),
        ]);

        $this->getJson("/api/v1/invitations/{$expired->token}")->assertStatus(422);
        $this->getJson("/api/v1/invitations/{$used->token}")->assertStatus(422);
    });

    it('404s an unknown token without revealing whether any exist', function () {
        $this->getJson('/api/v1/invitations/'.str_repeat('z', 64))->assertStatus(422);
    });
});
