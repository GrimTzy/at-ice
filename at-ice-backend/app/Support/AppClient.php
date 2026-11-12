<?php

namespace App\Support;

use Illuminate\Http\Request;

// True when the request comes from the AT-ICE Android app: it sends the app's header AND the key
// that only the app build has. A browser or a copied header without the key is not the app.
class AppClient
{
    public static function verified(Request $request): bool
    {
        $key = (string) config('appclient.key', '');
        if ($key === '' || $request->header('X-AT-ICE-App') !== 'android') {
            return false;
        }

        return hash_equals($key, (string) $request->header('X-AT-ICE-App-Key', ''));
    }
}
