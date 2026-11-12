<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

// Sign-out after inactivity: admin, HR and customer after 30 minutes. The
// employees on their phones (Checker, Salesman, Maintenance, Driver) never by
// idle. Real tokens are used (not actingAs), because the rule lives on the token.
class IdleTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Right-pass-1!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();
    }

    private function user(string $role, ?string $sub = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => "{$role}{$sub}@atice.cloud",
            'password' => self::PASSWORD, 'role' => $role, 'sub_role' => $sub,
        ]);
    }

    /** A real sign-in through the API, like the web app does it. */
    private function signIn(User $user): string
    {
        return $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()->json('token');
    }

    /** One request as that token. The guard remembers its user for a whole test, so reset it first. */
    private function as(string $token, string $method = 'GET', string $uri = '/api/me')
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $uri);
    }

    public function test_login_tells_the_web_app_how_long_a_person_may_be_idle(): void
    {
        foreach (['admin', 'hr', 'customer'] as $role) {
            $this->user($role);
            $this->postJson('/api/login', ['email' => "{$role}@atice.cloud", 'password' => self::PASSWORD])->assertJsonPath('idle_minutes', 30);
        }

        $this->user('driver');
        $this->postJson('/api/login', ['email' => 'driver@atice.cloud', 'password' => self::PASSWORD])->assertJsonPath('idle_minutes', null);
        foreach (['checker', 'salesman', 'maintenance'] as $sub) {
            $this->user('employee', $sub);
            $this->postJson('/api/login', ['email' => "employee{$sub}@atice.cloud", 'password' => self::PASSWORD])->assertJsonPath('idle_minutes', null);
        }
    }

    public function test_an_admin_who_does_nothing_for_half_an_hour_is_signed_out(): void
    {
        $token = $this->signIn($this->user('admin'));

        $this->travel(20)->minutes();
        $this->as($token)->assertOk();

        // 20 + 32 idle minutes: past 30 minutes and the one minute of grace
        $this->travel(32)->minutes();
        $this->as($token)->assertStatus(401)->assertJsonPath('reason', 'idle');

        // the token is gone for good, not just refused once
        $this->assertSame(0, PersonalAccessToken::count());
        $this->as($token)->assertStatus(401);
    }

    public function test_working_keeps_the_session_alive(): void
    {
        $token = $this->signIn($this->user('hr'));

        foreach (range(1, 5) as $i) {
            $this->travel(25)->minutes(); // 125 minutes in total, never 30 idle
            $this->as($token)->assertOk();
        }
    }

    public function test_the_ping_counts_as_activity(): void
    {
        $token = $this->signIn($this->user('admin'));

        $this->travel(25)->minutes();
        $this->as($token, 'POST', '/api/session/ping')->assertOk();

        $this->travel(25)->minutes(); // 50 since sign-in, 25 since the ping
        $this->as($token)->assertOk();
    }

    public function test_the_pages_own_polling_does_not_count_as_activity(): void
    {
        $token = $this->signIn($this->user('admin'));

        $this->travel(25)->minutes();
        $this->as($token, 'GET', '/api/live-feed')->assertOk();

        // an open page nobody looks at keeps polling; it must still time out
        $this->travel(7)->minutes();
        $this->as($token)->assertStatus(401);
    }

    public function test_the_polling_is_refused_too_once_the_time_is_up(): void
    {
        $token = $this->signIn($this->user('customer'));

        $this->travel(32)->minutes();
        $this->as($token, 'GET', '/api/live-feed')->assertStatus(401)->assertJsonPath('reason', 'idle');
    }

    public function test_the_employees_on_their_phones_are_never_signed_out_for_being_idle(): void
    {
        $tokens = [
            $this->signIn($this->user('employee', 'checker')),
            $this->signIn($this->user('employee', 'maintenance')),
            $this->signIn($this->user('employee', 'salesman')),
            $this->signIn($this->user('driver')),
        ];

        $this->travel(5)->hours();

        foreach ($tokens as $token) {
            $this->as($token)->assertOk();
            $this->as($token, 'GET', '/api/live-feed')->assertOk();
            $this->as($token, 'POST', '/api/session/ping')->assertOk();
        }

        // nothing is tracked for them: no idle record, not at sign-in, not after use
        $this->assertCount(4, PersonalAccessToken::all());
        foreach (PersonalAccessToken::all() as $row) {
            $this->assertNull(Cache::get('session-activity:'.$row->getKey()));
        }
    }

    public function test_a_sign_in_from_before_this_rule_starts_counting_when_it_is_next_used(): void
    {
        $token = $this->user('admin')->createToken('auth_token')->plainTextToken; // no activity recorded

        $this->travel(2)->hours();
        $this->as($token)->assertOk(); // nothing to measure from yet, so it starts now

        $this->travel(32)->minutes();
        $this->as($token)->assertStatus(401);
    }

    public function test_one_persons_idle_time_does_not_end_another_session(): void
    {
        $quiet = $this->signIn($this->user('admin'));
        $busy = $this->signIn($this->user('hr'));

        $this->travel(20)->minutes();
        $this->as($busy)->assertOk();

        $this->travel(15)->minutes();
        $this->as($busy)->assertOk();
        $this->as($quiet)->assertStatus(401); // 35 minutes of nothing

        $this->assertSame(1, PersonalAccessToken::count());
    }
}
