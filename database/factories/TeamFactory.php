<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TeamFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'owner_id' => User::factory(),
            'plan' => 'free',
            'privacy_mode' => 'cloud_redacted',
            'monthly_ai_budget_usd' => 25.00,
        ];
    }

    public function localOnly(): static
    {
        return $this->state(fn () => ['privacy_mode' => 'local_only']);
    }
}
