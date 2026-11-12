<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

// Session limits are for the web only. A browser sign-in has the idle limit of
// its role and ends after 12 hours; verified Android mobile-role sessions stay
// signed in until sign-out, deactivation or password change.
class SessionPlatformTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Right-pass-1!';

    private const WEBVIEW = 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP1A; wv) AppleWebKit/537.36 Chrome/126 Mobile Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();
        config(['appclient.key' => 'test-app-key']);
    }

    private function user(string $role, ?string $sub = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => "{$role}{$sub}".uniqid()."@atice.cloud",
            'password' => self::PASSWORD, 'role' => $role, 'sub_role' => $sub,
        ]);
    }

    private function signIn(User $user, ?string $platform = null, array $headers = []): string
    {
        $body = ['email' => $user->email, 'password' => self::PASSWORD];
        if ($platform) {
            $body['platform'] = $platform;
        }

        if ($platform === 'android' && $user->usesMobileApp()) {
            $headers += ['X-AT-ICE-App' => 'android', 'X-AT-ICE-App-Key' => 'test-app-key'];
        }

        return $this->flushHeaders()->withHeaders($headers)->postJson('/api/login', $body)->assertOk()->json('token');
    }

    private function as(string $token, string $method = 'GET', string $uri = '/api/me', array $data = [])
    {
        $this->app['auth']->forgetGuards();

        $headers = PersonalAccessToken::findToken($token)?->name === 'android-app'
            ? ['X-AT-ICE-App' => 'android', 'X-AT-ICE-App-Key' => 'test-app-key'] : [];

        return $this->flushHeaders()->withHeaders($headers)->withToken($token)->json($method, $uri, $data);
    }

    public function test_a_web_token_is_signed_out_after_30_idle_minutes_and_the_apk_is_not(): void
    {
        foreach (['admin', 'hr', 'customer'] as $role) {
            $user = $this->user($role);
            $web = $this->signIn($user);
            $apk = $this->signIn($this->user('driver'), 'android');

            $this->travel(40)->minutes();
            $this->as($web)->assertStatus(401)->assertJsonPath('reason', 'idle');
            $this->as($apk)->assertOk();
            $this->travelBack();
        }
    }

    public function test_the_apk_login_has_no_idle_limit_and_no_expiry(): void
    {
        $admin = $this->user('driver');
        $this->withHeaders(['X-AT-ICE-App' => 'android', 'X-AT-ICE-App-Key' => 'test-app-key'])->postJson('/api/login', ['email' => $admin->email, 'password' => self::PASSWORD, 'platform' => 'android'])
            ->assertOk()->assertJsonPath('idle_minutes', null);

        $row = PersonalAccessToken::first();
        $this->assertNull($row->expires_at);
        $this->assertNull(Cache::get('session-activity:'.$row->getKey()));
    }

    public function test_a_web_token_ends_after_12_hours_even_with_activity_and_the_apk_keeps_working(): void
    {
        $admin = $this->user('admin');
        $web = $this->signIn($admin);
        $apk = $this->signIn($this->user('driver'), 'android');

        foreach (range(1, 28) as $i) {
            $this->travel(25)->minutes(); // never idle, 11 h 40 min in the end
            $this->as($web)->assertOk();
            $this->as($apk)->assertOk();
        }

        $this->travel(25)->minutes(); // past 12 hours
        $this->as($web)->assertStatus(401);
        $this->as($apk)->assertOk();

        $this->travel(60)->days();
        $this->as($apk)->assertOk();
    }

    public function test_an_employee_on_the_web_has_no_idle_limit_but_the_token_ends_after_12_hours(): void
    {
        $token = $this->signIn($this->user('driver'));

        $this->travel(11)->hours();
        $this->as($token)->assertOk();
        $this->travel(2)->hours();
        $this->as($token)->assertStatus(401);
    }

    public function test_an_unverified_android_webview_remains_a_web_session(): void
    {
        $token = $this->signIn($this->user('admin'), null, ['User-Agent' => self::WEBVIEW]);

        $this->assertSame('auth_token', PersonalAccessToken::first()->name);
        $this->travel(5)->hours();
        $this->as($token)->assertUnauthorized();
    }

    public function test_a_normal_phone_browser_is_still_web(): void
    {
        $token = $this->signIn($this->user('admin'), 'web', ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126 Mobile Safari/537.36']);

        $this->assertSame('auth_token', PersonalAccessToken::first()->name);
        $this->travel(40)->minutes();
        $this->as($token)->assertStatus(401);
    }

    public function test_the_apk_token_still_dies_on_sign_out(): void
    {
        $apk = $this->signIn($this->user('driver'), 'android');

        $this->as($apk, 'POST', '/api/logout')->assertOk();
        $this->as($apk)->assertStatus(401);
    }

    public function test_the_apk_token_still_dies_when_the_account_is_deactivated(): void
    {
        $driver = $this->user('driver');
        $employee = Employee::create([
            'user_id' => $driver->id, 'employee_id' => 'EMP-0001', 'first_name' => 'Pedro', 'last_name' => 'Santos',
            'position' => 'Driver', 'daily_rate' => 600, 'date_hired' => '2026-01-01', 'is_active' => true,
        ]);
        $apk = $this->signIn($driver, 'android');
        $admin = $this->signIn($this->user('admin'));

        $this->as($apk)->assertOk();
        $this->as($admin, 'DELETE', '/api/employees/'.$employee->id)->assertOk();

        $this->as($apk)->assertStatus(401);
        $this->postJson('/api/login', ['email' => $driver->email, 'password' => self::PASSWORD, 'platform' => 'android'])->assertStatus(403);
    }

    public function test_the_apk_token_still_dies_when_the_password_changes(): void
    {
        $admin = $this->user('driver');
        $apk = $this->signIn($admin, 'android');
        $web = $this->signIn($admin);

        $this->as($web, 'PUT', '/api/change-password', [
            'current_password' => self::PASSWORD, 'password' => 'New-pass-2@x', 'password_confirmation' => 'New-pass-2@x',
        ])->assertOk();

        $this->as($apk)->assertStatus(401);
        $this->as($web)->assertOk();
    }

    public function test_platform_does_not_change_the_role_or_what_a_person_can_open(): void
    {
        $customer = $this->user('customer');
        foreach ([null, 'web', 'android'] as $platform) {
            $body = ['email' => $customer->email, 'password' => self::PASSWORD];
            if ($platform) {
                $body['platform'] = $platform;
            }
            $this->postJson('/api/login', $body)->assertOk()->assertJsonPath('role', 'customer');
        }

        $apk = $this->signIn($customer, 'android');
        $this->as($apk, 'GET', '/api/employees')->assertForbidden();
        $this->assertSame(['*'], PersonalAccessToken::where('name', 'auth_token')->first()->abilities);
    }

    public function test_an_unknown_platform_is_refused(): void
    {
        $admin = $this->user('admin');

        $this->postJson('/api/login', ['email' => $admin->email, 'password' => self::PASSWORD, 'platform' => 'root'])
            ->assertStatus(422);
        $this->assertSame(0, PersonalAccessToken::count());
    }
}
