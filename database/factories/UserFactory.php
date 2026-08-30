<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'timezone' => 'UTC',
            'theme' => 'dark',
            'onboarded_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    /** Creates the user with an owned team and sets it current. Used by every feature test. */
    public function withTeam(TeamRole|string $role = TeamRole::OWNER): static
    {
        return $this->afterCreating(function ($user) use ($role): void {
            $team = Team::factory()->create(['owner_id' => $user->id]);
            $user->teams()->attach($team, [
                'role' => $role instanceof TeamRole ? $role->value : $role,
                'joined_at' => now(),
            ]);
            $user->forceFill(['current_team_id' => $team->id])->save();
        });
    }
}
