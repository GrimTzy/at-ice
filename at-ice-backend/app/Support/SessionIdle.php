<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sign-out after inactivity. The last time a person did something is kept in
 * the cache per sign-in (token), not in the token row: Sanctum stamps the row on
 * every request, including the pages' own background polling, which is not
 * activity. Limits per role: config/atice.php session_idle.
 */
class SessionIdle
{
    private const KEY = 'session-activity:';

    /** Name of the token the Android app gets: no idle and no absolute limit. */
    public const APK_TOKEN = 'android-app';

    public static function isApk(PersonalAccessToken $token): bool
    {
        return in_array($token->name, [self::APK_TOKEN, 'auth_token_apk'], true);
    }

    /** Minutes of inactivity allowed for this user, or null for no limit. */
    public static function minutesFor(User $user): ?int
    {
        $key = $user->role === 'employee' ? (string) $user->sub_role : (string) $user->role;
        $minutes = config("atice.session_idle.minutes.{$key}");

        return $minutes ? (int) $minutes : null;
    }

    /** Seconds since the last action of this sign-in, or null when nothing is recorded. */
    public static function idleSeconds(PersonalAccessToken $token): ?int
    {
        $last = Cache::get(self::KEY.$token->getKey());

        return $last ? max(0, now()->timestamp - (int) $last) : null;
    }

    /** The person did something just now. */
    public static function touch(PersonalAccessToken $token): void
    {
        // kept a day past the longest token life, then the cache drops it by itself
        Cache::put(self::KEY.$token->getKey(), now()->timestamp, now()->addDays(2));
    }

    /** True once the limit (plus the grace) has passed without an action. */
    public static function expired(User $user, PersonalAccessToken $token): bool
    {
        $minutes = self::minutesFor($user);
        $idle = self::idleSeconds($token);
        if (self::isApk($token) || ! $minutes || $idle === null) {
            return false;
        }

        return $idle > $minutes * 60 + (int) config('atice.session_idle.grace', 60);
    }

    /** End this sign-in for good. */
    public static function end(PersonalAccessToken $token): void
    {
        Cache::forget(self::KEY.$token->getKey());
        $token->delete();
    }
}
