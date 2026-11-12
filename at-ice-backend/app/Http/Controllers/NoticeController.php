<?php

namespace App\Http\Controllers;

use App\Models\LiveEvent;
use Illuminate\Http\Request;

// The notice bell in every portal's header (QA review, 2026-11-11): the pop-up
// notices of the last week that reached this user stay listed until read, so a
// handoff between roles is not missed when the toast is gone. Same audience as
// the live feed (LiveEvent::isFor); the user's own actions are left out.
class NoticeController extends Controller
{
    private const SHOWN = 30;

    // GET /api/notices
    public function index(Request $request)
    {
        $user = $request->user();
        $readAt = $user->notices_read_at;

        $notices = LiveEvent::where('created_at', '>=', now()->subDays(7))
            ->where(fn ($q) => $q->whereNull('actor_id')->orWhere('actor_id', '!=', $user->id))
            ->orderByDesc('id')
            ->limit(300)
            ->get()
            ->filter(fn ($e) => $e->isFor($user))
            ->values();

        return response()->json([
            'unread' => $notices->filter(fn ($e) => ! $readAt || $e->created_at->gt($readAt))->count(),
            'notices' => $notices->take(self::SHOWN)->map(fn ($e) => [
                'id' => $e->id,
                'module' => $e->module,
                'message' => $e->message,
                'created_at' => $e->created_at,
                'unread' => ! $readAt || $e->created_at->gt($readAt),
            ])->values(),
        ]);
    }

    // POST /api/notices/read : everything up to now counts as read
    public function read(Request $request)
    {
        $request->user()->forceFill(['notices_read_at' => now()])->save();

        return response()->json(['unread' => 0]);
    }
}
