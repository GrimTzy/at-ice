<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

// The newest Android app (APK) this server offers. The app asks it when it opens and,
// if its own build number is lower, offers the user the update. The numbers are written
// by `npm run apk` (at-ice-frontend) into public/downloads/app-version.json next to
// the APK; nothing in it is secret, so no login is needed.
class AppVersionController extends Controller
{
    public function show(): JsonResponse
    {
        $file = public_path('downloads/app-version.json');
        $info = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        $build = is_array($info) ? (int) ($info['build'] ?? 0) : 0;
        $apk = is_file(public_path('downloads/AT-ICE.apk'));

        if ($build <= 0 || ! $apk) {
            return response()->json(['build' => 0]);
        }

        return response()->json([
            'build' => $build,
            'name' => (string) ($info['name'] ?? ''),
            'apk_url' => url('/api/app-download'),
        ]);
    }

    // The APK itself, sent as a file named AT-ICE.apk with the Android app type, so a phone's browser
    // saves it as an .apk (not a zip) on any host, localhost or live.
    public function download()
    {
        $path = public_path('downloads/AT-ICE.apk');
        if (! is_file($path)) {
            return response()->json(['message' => 'The app file is not available yet.'], 404);
        }

        return response()->download($path, 'AT-ICE.apk', ['Content-Type' => 'application/vnd.android.package-archive']);
    }
}
