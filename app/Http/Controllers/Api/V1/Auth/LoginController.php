<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ]);

        if (! Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            $request->boolean('remember')
        )) {
            throw ValidationException::withMessages([
                // Deliberately identical for unknown email and wrong password:
                // anything else is an account-enumeration oracle.
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $response = ['data' => (new AuthUserResource($user->load('teams')))->resolve($request)];

        // Browser clients use the session cookie; CLI/CI ask for a bearer token.
        if ($device = $request->input('device_name')) {
            $response['data']['token'] = $user->createToken($device)->plainTextToken;
        }

        // Only stateful (SPA cookie) requests carry a session. A bearer-token
        // login from the CLI or CI has no session store, and calling session()
        // there throws rather than returning null.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json($response);
    }

    public function destroy(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out.']);
    }
}
