<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

// A website login ends 24 hours after the sign-in (sanctum.web_expiration); a login from the Android
// app, for the four mobile roles only, has no end time.
class LoginTokenLifetimeTest extends TestCase
{
    use RefreshDatabase;

    private const APP_KEY = 'test-app-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['appclient.key' => self::APP_KEY]);
    }

    // what the Android app sends: its header and its key
    private function appHeader(): array
    {
        return ['X-AT-ICE-App' => 'android', 'X-AT-ICE-App-Key' => self::APP_KEY];
    }

    private function user(string $role, ?string $subRole = null): User
    {
        return User::create([
            'name' => 'Test', 'email' => $role.($subRole ?? '').uniqid().'@atice.cloud', 'password' => 'secret-password',
            'role' => $role, 'sub_role' => $subRole,
        ]);
    }

    private function login(User $user, array $headers = []): string
    {
        return $this->withHeaders($headers)
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertOk()
            ->json('token');
    }

    private function me(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()
            ->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/me');
    }

    public function test_a_website_login_lasts_24_hours(): void
    {
        $token = $this->login($this->user('admin'));

        $stored = PersonalAccessToken::findToken($token);
        $this->assertSame('auth_token', $stored->name);
        $this->assertEqualsWithDelta(1440, now()->diffInMinutes($stored->expires_at), 1);
    }

    public function test_a_website_session_is_not_cut_by_inactivity(): void
    {
        $token = $this->login($this->user('admin'));

        $this->travel(90)->minutes();
        $this->me($token)->assertOk();
    }

    public function test_a_website_session_ends_after_24_hours(): void
    {
        $token = $this->login($this->user('hr'));

        $this->travel(23)->hours();
        $this->me($token)->assertOk();
        $this->travel(2)->hours();
        $this->me($token)->assertUnauthorized();
    }

    public function test_an_app_login_of_a_mobile_role_has_no_end_time(): void
    {
        foreach ([['driver', null], ['employee', 'checker'], ['employee', 'salesman'], ['employee', 'maintenance']] as [$role, $sub]) {
            $token = $this->login($this->user($role, $sub), $this->appHeader());

            $stored = PersonalAccessToken::findToken($token);
            $this->assertSame('android-app', $stored->name);
            $this->assertNull($stored->expires_at);
        }
    }

    public function test_an_app_login_still_works_long_after(): void
    {
        $token = $this->login($this->user('driver'), $this->appHeader());

        $this->travel(40)->days();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$token] + $this->appHeader())->getJson('/api/me')->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($token)->expires_at);
    }

    public function test_a_copied_app_header_without_the_key_is_a_website_session(): void
    {
        foreach ([['driver', null], ['employee', 'checker']] as [$role, $sub]) {
            $token = $this->login($this->user($role, $sub), ['X-AT-ICE-App' => 'android']);

            $stored = PersonalAccessToken::findToken($token);
            $this->assertSame('auth_token', $stored->name);
            $this->assertNotNull($stored->expires_at);
        }
    }

    public function test_a_wrong_app_key_is_a_website_session(): void
    {
        $token = $this->login($this->user('driver'), ['X-AT-ICE-App' => 'android', 'X-AT-ICE-App-Key' => 'wrong']);

        $this->assertSame('auth_token', PersonalAccessToken::findToken($token)->name);
    }

    public function test_the_app_header_gives_other_roles_no_longer_login(): void
    {
        foreach ([['admin', null], ['hr', null], ['customer', null]] as [$role, $sub]) {
            $token = $this->login($this->user($role, $sub), $this->appHeader());

            $stored = PersonalAccessToken::findToken($token);
            $this->assertSame('auth_token', $stored->name);
            $this->assertNotNull($stored->expires_at);
        }
    }

    public function test_an_app_session_is_refused_from_a_browser_without_the_key(): void
    {
        $token = $this->login($this->user('driver'), $this->appHeader());

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$token] + $this->appHeader())->getJson('/api/me')->assertOk();
    }

    public function test_a_website_session_works_without_the_key(): void
    {
        $token = $this->login($this->user('driver'));

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/me')->assertOk();
    }

    public function test_logging_out_ends_a_session(): void
    {
        foreach ([[], $this->appHeader()] as $i => $headers) {
            $token = $this->login($this->user('driver', $i ? 'x' : null), $headers);

            $this->app['auth']->forgetGuards();
            $this->flushHeaders()->withHeaders(['Authorization' => 'Bearer '.$token] + ($i ? $this->appHeader() : []))
                ->postJson('/api/logout')->assertOk();
            $this->assertNull(PersonalAccessToken::findToken($token));
        }
    }
}
