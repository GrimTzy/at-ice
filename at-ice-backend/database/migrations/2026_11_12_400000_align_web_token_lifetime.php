<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $hours = (int) config('atice.web_token_hours', 12);
        DB::table('personal_access_tokens')
            ->whereNotIn('name', ['android-app', 'auth_token_apk'])
            ->orderBy('id')->each(function ($token) use ($hours) {
                $expiry = Carbon::parse($token->created_at ?? now())->addHours($hours);
                if (! $token->expires_at || Carbon::parse($token->expires_at)->gt($expiry)) {
                    DB::table('personal_access_tokens')->where('id', $token->id)->update(['expires_at' => $expiry]);
                }
            });
    }

    public function down(): void
    {
        // Shortened sign-ins stay shortened; rollback must not revive an expired token.
    }
};
