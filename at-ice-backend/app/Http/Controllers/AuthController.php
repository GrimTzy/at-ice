<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // ─────────────────────────────────────────────
    // LOGIN with Brute Force Protection
    // 5 attempts → 3-minute lockout (per email + IP)
    // ─────────────────────────────────────────────
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // Build a unique throttle key: email + IP
        $throttleKey = Str::lower($request->email) . '|' . $request->ip();

        // ── Check if already locked out ──
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $minutes = ceil($seconds / 60);

            return response()->json([
                'message' => "Too many login attempts. Please try again in {$minutes} minute(s).",
                'retry_after_seconds' => $seconds,
            ], 429);
        }

        // ── Attempt login ──
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            // Increment failed attempt (decays after 180 seconds = 3 minutes)
            RateLimiter::hit($throttleKey, 180);

            $remaining = 5 - RateLimiter::attempts($throttleKey);

            return response()->json([
                'message'           => 'Invalid credentials.',
                'attempts_remaining' => max(0, $remaining),
            ], 401);
        }

        // ── Success — clear throttle ──
        RateLimiter::clear($throttleKey);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token'    => $token,
            'role'     => $user->role,
            'sub_role' => $user->sub_role ?? null,
            'user'     => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    // ─────────────────────────────────────────────
    // LOGOUT
    // ─────────────────────────────────────────────
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    // ─────────────────────────────────────────────
    // GET CURRENT USER
    // ─────────────────────────────────────────────
    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}