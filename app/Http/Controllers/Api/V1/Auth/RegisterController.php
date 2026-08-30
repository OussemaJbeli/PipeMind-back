<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthUserResource;
use App\Models\RemediationPolicy;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()->min(10)],
            'team_name' => ['sometimes', 'string', 'max:120'],
        ]);

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'timezone' => $request->input('timezone', 'UTC'),
            ]);

            $teamName = $data['team_name'] ?? $data['name']."'s workspace";

            $team = Team::create([
                'name' => $teamName,
                'slug' => Str::slug($teamName).'-'.Str::lower(Str::random(5)),
                'owner_id' => $user->id,
            ]);

            $user->teams()->attach($team, [
                'role' => TeamRole::OWNER->value,
                'joined_at' => now(),
            ]);

            $user->forceFill(['current_team_id' => $team->id])->save();

            // Seed the default policy set. A missing policy means FORBIDDEN, so a
            // workspace without these could never remediate anything.
            foreach (config('pipemind.default_policies') as $policy) {
                RemediationPolicy::create([...$policy, 'team_id' => $team->id, 'created_by' => $user->id]);
            }

            return $user;
        });

        Auth::login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json(
            ['data' => (new AuthUserResource($user->load('teams')))->resolve($request)],
            201
        );
    }
}
