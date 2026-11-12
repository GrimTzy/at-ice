<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Login tokens now carry their own end time (config/sanctum.php). Tokens made before this had
// none and relied on the global limit, which is now off, so each one gets the end time it would
// have had: when it was made plus the website lifetime. Without this they would never expire.
return new class extends Migration
{
    public function up(): void
    {
        $minutes = (int) config('sanctum.web_expiration', 1440);

        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->where('name', '!=', 'android-app')
            ->orderBy('id')
            ->each(function ($token) use ($minutes) {
                $made = $token->created_at ? \Illuminate\Support\Carbon::parse($token->created_at) : now();
                DB::table('personal_access_tokens')->where('id', $token->id)->update(['expires_at' => $made->addMinutes($minutes)]);
            });
    }

    public function down(): void
    {
        // The end times stay: removing them would make old tokens last forever.
    }
};
