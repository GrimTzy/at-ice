<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The Android app sends its push-notification address after sign-in and removes it at logout,
// so notices reach only the phones of signed-in mobile users.
class DeviceTokenController extends Controller
{
    // POST /api/device-tokens  { token }
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:512'],
        ], [
            'token.required' => 'The device token is required.',
            'token.max' => 'The device token is too long.',
        ]);

        // Only the four mobile roles use the app; other accounts get no push address.
        if (! $request->user()->usesMobileApp()) {
            return response()->json(['message' => 'Push notifications are for the mobile app only.'], 403);
        }

        // A phone changes hands: the token moves to whoever signed in on it last.
        DeviceToken::where('token', $data['token'])->where('user_id', '!=', $request->user()->id)->delete();

        DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => 'android', 'last_seen_at' => now()],
        );

        return response()->json(['message' => 'Saved.']);
    }

    // DELETE /api/device-tokens  { token }
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        DeviceToken::where('token', $data['token'])->where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'Removed.']);
    }
}
