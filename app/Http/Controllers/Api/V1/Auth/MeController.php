<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthUserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): array
    {
        return ['data' => (new AuthUserResource($request->user()->load('teams')))->resolve($request)];
    }

    public function update(Request $request): array
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'theme' => ['sometimes', 'in:dark,light,system'],
            'locale' => ['sometimes', 'string', 'max:10'],
        ]);

        $request->user()->update($data);

        return ['data' => (new AuthUserResource($request->user()->fresh()->load('teams')))->resolve($request)];
    }

    /**
     * Marks onboarding finished, so the router guard stops redirecting.
     *
     * Separate from the wizard's own steps on purpose: onboarding is complete
     * when the user says so — including by skipping — not when some checklist
     * happens to be satisfied. Deriving it would trap anyone who legitimately
     * wants no AI provider yet.
     */
    public function completeOnboarding(Request $request): array
    {
        $user = $request->user();

        if (! $user->onboarded_at) {
            $user->forceFill(['onboarded_at' => now()])->save();
        }

        return ['data' => (new AuthUserResource($user->fresh()->load('teams')))->resolve($request)];
    }
}
