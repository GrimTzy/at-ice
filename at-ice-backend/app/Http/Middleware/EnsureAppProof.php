<?php

namespace App\Http\Middleware;

use App\Support\AppClient;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

// An app session (token name "android-app") is only for requests that prove they come from the
// AT-ICE app (the app key). The same token sent from a browser, without the key, is refused.
class EnsureAppProof
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if ($bearer) {
            $token = PersonalAccessToken::findToken($bearer);
            if ($token && $token->name === 'android-app' && ! AppClient::verified($request)) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        }

        return $next($request);
    }
}
