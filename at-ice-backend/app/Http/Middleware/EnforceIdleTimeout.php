<?php

namespace App\Http\Middleware;

use App\Support\SessionIdle;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a sign-in that has been idle for longer than its role allows (see
 * App\Support\SessionIdle). It runs on every protected request, so it also
 * catches a computer that was switched off or a tab that was closed: the next
 * request after the limit gets a 401 and the token is deleted.
 *
 * An open tab keeps its sign-in alive with a ping every minute (POST
 * /session/ping), so only a closed tab or a switched-off computer goes quiet.
 * `idle:passive` is for requests the page makes by itself (the live feed): they
 * are checked but do not count; the ping does. Every other request counts.
 */
class EnforceIdleTimeout
{
    public function handle(Request $request, Closure $next, string $mode = 'active'): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Only real sign-ins have a token row (tests that act as a user do not)
        if (! $user || ! $token instanceof PersonalAccessToken || SessionIdle::isApk($token) || ! SessionIdle::minutesFor($user)) {
            return $next($request);
        }

        if (SessionIdle::expired($user, $token)) {
            SessionIdle::end($token);

            return response()->json([
                'message' => 'You were signed out because you were inactive. Please sign in again.',
                'reason' => 'idle',
            ], 401);
        }

        // Nothing recorded yet (a sign-in from before this rule): start counting now
        if ($mode !== 'passive' || SessionIdle::idleSeconds($token) === null) {
            SessionIdle::touch($token);
        }

        return $next($request);
    }
}
