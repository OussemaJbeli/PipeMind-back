<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(): array
    {
        $team = currentTeam();

        return ['data' => $team->users()
            ->orderByPivot('role')
            ->get()
            ->map(fn (User $user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'initials' => $user->initials(),
                'avatar_url' => $user->avatar_url,
                'job_title' => $user->job_title,
                'role' => $user->pivot->role,
                'joined_at' => $user->pivot->joined_at,
                // The owner cannot be demoted or removed, and the UI needs to
                // know that before rendering the controls rather than after a
                // rejected request.
                'is_owner' => $user->id === $team->owner_id,
                'is_you' => $user->id === auth()->id(),
            ])->all(),
        ];
    }

    public function update(Request $request, string $user): JsonResponse|array
    {
        $team = currentTeam();
        $member = $this->member($team, $user);

        $data = $request->validate([
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
        ]);

        // A team with no owner has nobody who can delete it or change billing.
        if ($member->id === $team->owner_id) {
            return response()->json([
                'message' => 'The workspace owner\'s role cannot be changed. Transfer ownership first.',
                'error_code' => 'OWNER_ROLE_IMMUTABLE',
                'retryable' => false,
            ], 422);
        }

        $team->users()->updateExistingPivot($member->id, ['role' => $data['role']]);

        activity_log(null, 'member.role_changed', 'info', $team->name,
            "{$member->name} is now {$data['role']}", $member);

        return ['data' => ['uuid' => $member->uuid, 'role' => $data['role']]];
    }

    public function destroy(string $user): JsonResponse|array
    {
        $team = currentTeam();
        $member = $this->member($team, $user);

        if ($member->id === $team->owner_id) {
            return response()->json([
                'message' => 'The workspace owner cannot be removed.',
                'error_code' => 'OWNER_IMMUTABLE',
                'retryable' => false,
            ], 422);
        }

        $team->users()->detach($member->id);

        // Their current_team_id now points at a team they cannot see, which
        // would leave them staring at an empty workspace with no way out.
        if ($member->current_team_id === $team->id) {
            $member->forceFill([
                'current_team_id' => $member->teams()->where('teams.id', '!=', $team->id)->value('teams.id'),
            ])->save();
        }

        activity_log(null, 'member.removed', 'warning', $team->name,
            "{$member->name} was removed from the workspace", $member);

        return ['data' => ['removed' => true]];
    }

    public function invitations(): array
    {
        return ['data' => currentTeam()->invitations()
            ->whereNull('accepted_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'uuid' => $invitation->uuid,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
                'expired' => $invitation->expires_at?->isPast() ?? false,
                'invited_at' => $invitation->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function invite(Request $request): JsonResponse|array
    {
        $team = currentTeam();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
        ]);

        if ($team->users()->where('email', $data['email'])->exists()) {
            return response()->json([
                'message' => 'That person is already in this workspace.',
                'error_code' => 'ALREADY_A_MEMBER',
                'retryable' => false,
            ], 422);
        }

        // updateOrCreate, not create: UNIQUE (team_id, email) means a second
        // invite to the same address is a re-invite, and it should refresh the
        // token and expiry rather than fail.
        $invitation = TeamInvitation::updateOrCreate(
            ['team_id' => $team->id, 'email' => $data['email']],
            [
                'role' => $data['role'],
                'token' => Str::random(64),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
            ],
        );

        activity_log(null, 'member.invited', 'info', $team->name,
            "{$data['email']} invited as {$data['role']}", $invitation);

        return ['data' => [
            'uuid' => $invitation->uuid,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            // Returned so the UI can offer a copyable link. Mail delivery lands
            // in roadmaps/20; until then the link is how somebody actually joins.
            'accept_url' => rtrim((string) config('pipemind.frontend_url'), '/')."/invitations/{$invitation->token}",
        ]];
    }

    public function revoke(TeamInvitation $invitation): array
    {
        $invitation->delete();

        return ['data' => ['revoked' => true]];
    }

    /**
     * What an invitation is for, before signing in.
     *
     * Public and unauthenticated: the invitee needs to see which workspace and
     * who invited them in order to decide whether to create an account at all.
     * Returns only what a person holding the link already knows — the workspace
     * name, the inviter's name, the role, and the address it was sent to — so a
     * guessed token leaks nothing worth having.
     */
    public function preview(string $token): JsonResponse|array
    {
        $invitation = TeamInvitation::withoutGlobalScopes()
            ->with(['team:id,name,slug', 'inviter:id,name'])
            ->where('token', $token)
            ->first();

        if (! $invitation || $invitation->accepted_at || $invitation->expires_at->isPast()) {
            return response()->json([
                'message' => 'This invitation is no longer valid. Ask for a new one.',
                'error_code' => 'INVITATION_INVALID',
                'retryable' => false,
            ], 422);
        }

        return ['data' => [
            'team' => ['name' => $invitation->team->name, 'slug' => $invitation->team->slug],
            'invited_by' => $invitation->inviter?->name,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]];
    }

    /**
     * Accepting an invitation, by token.
     *
     * Deliberately outside the `team` middleware: the person accepting is not
     * yet a member of the team they are joining, so resolving a team context
     * first would reject them.
     */
    public function accept(Request $request, string $token): JsonResponse|array
    {
        $invitation = TeamInvitation::where('token', $token)->whereNull('accepted_at')->first();

        if (! $invitation || $invitation->expires_at->isPast()) {
            return response()->json([
                'message' => 'This invitation is no longer valid. Ask for a new one.',
                'error_code' => 'INVITATION_INVALID',
                'retryable' => false,
            ], 422);
        }

        $user = $request->user();

        // The invitation names an address; honouring it for a different account
        // would let a forwarded link grant access to the wrong person.
        if (! hash_equals(strtolower($invitation->email), strtolower($user->email))) {
            return response()->json([
                'message' => 'This invitation was sent to a different email address.',
                'error_code' => 'INVITATION_WRONG_EMAIL',
                'retryable' => false,
            ], 403);
        }

        $team = $invitation->team;

        $team->users()->syncWithoutDetaching([
            $user->id => [
                'role' => $invitation->role,
                'invited_by' => $invitation->invited_by,
                'joined_at' => now(),
            ],
        ]);

        $invitation->forceFill(['accepted_at' => now()])->save();
        $user->forceFill(['current_team_id' => $team->id])->save();

        return ['data' => [
            'team' => ['uuid' => $team->uuid, 'name' => $team->name, 'slug' => $team->slug],
            'role' => $invitation->role,
        ]];
    }

    /**
     * Resolves a member of THIS team, or 404s.
     *
     * Users belong to many teams, so the route binding cannot be team-scoped —
     * and both `updateExistingPivot` and `detach` affect zero rows for a
     * non-member, which would have returned a cheerful 200 for someone else's
     * user without changing anything.
     */
    private function member(Team $team, string $uuid): User
    {
        return $team->users()->where('users.uuid', $uuid)->firstOrFail();
    }

    /** @return array<int,array<string,string>> */
    public function roles(): array
    {
        return ['data' => collect(TeamRole::cases())
            ->reject(fn (TeamRole $role) => $role === TeamRole::OWNER)
            ->map(fn (TeamRole $role) => [
                'value' => $role->value,
                'label' => ucfirst($role->value),
                'permissions' => $role->permissions(),
            ])->values()->all(),
        ];
    }
}
