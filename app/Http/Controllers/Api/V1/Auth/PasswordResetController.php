<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Sends a reset link.
     *
     * Always answers the same way, whether or not the address exists. Any
     * difference — a different message, a different status, even a measurably
     * different response time — turns this into an account-enumeration oracle,
     * which is the whole reason the endpoint is dangerous.
     */
    public function request(Request $request): array
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($data);

        return ['data' => [
            'message' => 'If that address has an account, a reset link is on its way.',
        ]];
    }

    public function reset(Request $request): array
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                // Invalidates every "remember me" cookie: a password reset is
                // often a response to a compromise, and leaving old sessions
                // alive would defeat the point of resetting.
                'remember_token' => Str::random(60),
            ])->save();

            // API tokens too. A leaked bearer token survives a password change
            // otherwise, which is exactly the case this is meant to close.
            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return ['data' => ['message' => 'Your password has been reset. Sign in with it.']];
    }
}
