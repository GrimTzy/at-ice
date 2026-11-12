<?php

namespace App\Http\Controllers;

use App\Support\NavCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The "waiting" counts of the side menu, the bottom tabs and the bell, for
// the signed-in user (App\Support\NavCounts). The live-feed poll sends the
// same counts, so they update without a refresh.
class NavCountController extends Controller
{
    // GET nav-counts (every role): { counts: { "/path": n }, seen_paths: [...] }
    public function index(Request $request): JsonResponse
    {
        return response()->json(self::payload($request->user()));
    }

    // POST nav-counts/seen { path }: the user opened a news page; its badge goes to 0.
    public function seen(Request $request): JsonResponse
    {
        $data = $request->validate(['path' => 'required|string|max:100']);
        NavCounts::markSeen($request->user(), $data['path']);

        return response()->json(self::payload($request->user()));
    }

    // GET nav-counts/admin and nav-counts/hr (older clients): the plain counts.
    public function admin(Request $request): JsonResponse
    {
        return response()->json(NavCounts::for($request->user()));
    }

    public function hr(Request $request): JsonResponse
    {
        return response()->json(NavCounts::for($request->user()));
    }

    public static function payload($user): array
    {
        return ['counts' => (object) NavCounts::for($user), 'seen_paths' => NavCounts::seenPaths($user)];
    }
}
