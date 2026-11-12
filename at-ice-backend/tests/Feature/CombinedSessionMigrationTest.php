<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CombinedSessionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_shortens_only_web_tokens_without_extending_or_reviving_them(): void
    {
        config(['atice.web_token_hours' => 12]);
        $user = User::create(['name' => 'Migration test', 'email' => 'migration@test.invalid', 'password' => 'secret', 'role' => 'driver']);
        $web = $user->createToken('auth_token', ['*'], now()->addDay())->accessToken;
        $short = $user->createToken('auth_token', ['*'], now()->addHour())->accessToken;
        $app = $user->createToken('android-app')->accessToken;
        $legacy = $user->createToken('auth_token_apk')->accessToken;
        $migration = require database_path('migrations/2026_11_12_400000_align_web_token_lifetime.php');
        $migration->up();
        $this->assertEqualsWithDelta(720, now()->diffInMinutes($web->fresh()->expires_at), 1);
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($short->fresh()->expires_at), 1);
        $this->assertNull($app->fresh()->expires_at);
        $this->assertNull($legacy->fresh()->expires_at);
        $end = $web->fresh()->expires_at;
        $migration->up();
        $migration->down();
        $this->assertTrue($end->eq($web->fresh()->expires_at));
    }
}
