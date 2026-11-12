<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\LiveEvent;
use App\Models\User;
use App\Support\Push\PushNotifier;
use App\Support\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Phone notices: a live-feed notice goes to the phones of the people it is addressed to, and only
// to them. The real Firebase sender is replaced by a fake that records what it would send.
class PhonePushTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{tokens: array, title: string, body: string, data: array}> */
    private array $sent = [];

    /** @var list<string> */
    private array $invalid = [];

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->instance(PushSender::class, new class($test) implements PushSender {
            public function __construct(private PhonePushTest $test) {}

            public function send(array $tokens, string $title, string $body, array $data): array
            {
                $this->test->recordSend($tokens, $title, $body, $data);

                return $this->test->invalidTokens();
            }
        });
    }

    public function recordSend(array $tokens, string $title, string $body, array $data): void
    {
        $this->sent[] = compact('tokens', 'title', 'body', 'data');
    }

    public function invalidTokens(): array
    {
        return $this->invalid;
    }

    private function user(string $role, ?string $subRole = null, array $overrides = []): User
    {
        return User::create($overrides + [
            'name' => ucfirst($role).($subRole ? " {$subRole}" : ''),
            'email' => $role.($subRole ?? '').uniqid().'@atice.cloud',
            'password' => 'secret-password',
            'role' => $role,
            'sub_role' => $subRole,
        ]);
    }

    private function phone(User $user, string $token): void
    {
        DeviceToken::create(['user_id' => $user->id, 'token' => $token, 'platform' => 'android']);
    }

    private function notice(array $roles = [], array $userIds = [], ?int $actor = null): LiveEvent
    {
        return LiveEvent::create([
            'module' => 'deliveries', 'action' => 'updated', 'message' => 'Delivery updated',
            'roles' => $roles, 'user_ids' => $userIds, 'actor_id' => $actor,
        ]);
    }

    public function test_a_role_notice_reaches_only_the_phones_of_that_role(): void
    {
        $driver = $this->user('driver');
        $checker = $this->user('employee', 'checker');
        $this->phone($driver, str_repeat('d', 40));
        $this->phone($checker, str_repeat('c', 40));

        PushNotifier::forLiveEvent($this->notice(['driver']));

        $this->assertCount(1, $this->sent);
        $this->assertSame([str_repeat('d', 40)], $this->sent[0]['tokens']);
        $this->assertSame('AT-ICE', $this->sent[0]['title']);
        $this->assertSame('Delivery updated', $this->sent[0]['body']);
        $this->assertSame('deliveries', $this->sent[0]['data']['module']);
    }

    public function test_a_sub_role_notice_reaches_only_that_sub_role(): void
    {
        $this->phone($this->user('employee', 'checker'), str_repeat('c', 40));
        $this->phone($this->user('employee', 'salesman'), str_repeat('s', 40));

        PushNotifier::forLiveEvent($this->notice(['employee:salesman']));

        $this->assertSame([str_repeat('s', 40)], $this->sent[0]['tokens']);
    }

    public function test_a_user_id_notice_reaches_that_person(): void
    {
        $a = $this->user('driver');
        $b = $this->user('driver');
        $this->phone($a, str_repeat('a', 40));
        $this->phone($b, str_repeat('b', 40));

        PushNotifier::forLiveEvent($this->notice([], [$a->id]));

        $this->assertSame([str_repeat('a', 40)], $this->sent[0]['tokens']);
    }

    public function test_the_person_who_did_the_action_gets_no_notice(): void
    {
        $doer = $this->user('driver');
        $other = $this->user('driver');
        $this->phone($doer, str_repeat('x', 40));
        $this->phone($other, str_repeat('y', 40));

        PushNotifier::forLiveEvent($this->notice(['driver'], [], $doer->id));

        $this->assertSame([str_repeat('y', 40)], $this->sent[0]['tokens']);
    }

    public function test_a_notice_with_nobody_addressed_sends_nothing(): void
    {
        $this->phone($this->user('driver'), str_repeat('d', 40));

        PushNotifier::forLiveEvent($this->notice([], []));

        $this->assertSame([], $this->sent);
    }

    public function test_a_deactivated_person_gets_no_notice(): void
    {
        $gone = $this->user('driver', null, ['is_active' => false]);
        $this->phone($gone, str_repeat('g', 40));

        PushNotifier::forLiveEvent($this->notice(['driver']));

        $this->assertSame([], $this->sent);
    }

    public function test_a_phone_that_firebase_rejects_is_forgotten(): void
    {
        $driver = $this->user('driver');
        $this->phone($driver, str_repeat('d', 40));
        $this->phone($driver, str_repeat('e', 40));
        $this->invalid = [str_repeat('e', 40)];

        PushNotifier::forLiveEvent($this->notice(['driver']));

        $this->assertDatabaseMissing('device_tokens', ['token' => str_repeat('e', 40)]);
        $this->assertDatabaseHas('device_tokens', ['token' => str_repeat('d', 40)]);
    }

    public function test_the_app_saves_a_phone_for_a_mobile_role_only(): void
    {
        $this->actingAs($this->user('driver'), 'sanctum')
            ->postJson('/api/device-tokens', ['token' => str_repeat('k', 40)])
            ->assertOk();
        $this->assertDatabaseHas('device_tokens', ['token' => str_repeat('k', 40)]);

        $this->actingAs($this->user('admin'), 'sanctum')
            ->postJson('/api/device-tokens', ['token' => str_repeat('m', 40)])
            ->assertForbidden();
        $this->assertDatabaseMissing('device_tokens', ['token' => str_repeat('m', 40)]);
    }

    public function test_a_phone_moves_to_whoever_signs_in_on_it_last(): void
    {
        $first = $this->user('driver');
        $second = $this->user('employee', 'checker');
        $this->phone($first, str_repeat('p', 40));

        $this->actingAs($second, 'sanctum')->postJson('/api/device-tokens', ['token' => str_repeat('p', 40)])->assertOk();

        $this->assertSame(1, DeviceToken::where('token', str_repeat('p', 40))->count());
        $this->assertSame($second->id, DeviceToken::where('token', str_repeat('p', 40))->value('user_id'));
    }

    public function test_logout_removes_only_that_persons_phone(): void
    {
        $driver = $this->user('driver');
        $this->phone($driver, str_repeat('q', 40));

        $this->actingAs($this->user('driver'), 'sanctum')
            ->deleteJson('/api/device-tokens', ['token' => str_repeat('q', 40)])
            ->assertOk();
        $this->assertDatabaseHas('device_tokens', ['token' => str_repeat('q', 40)]);

        $this->actingAs($driver, 'sanctum')->deleteJson('/api/device-tokens', ['token' => str_repeat('q', 40)])->assertOk();
        $this->assertDatabaseMissing('device_tokens', ['token' => str_repeat('q', 40)]);
    }

    public function test_a_short_or_missing_token_is_refused(): void
    {
        $this->actingAs($this->user('driver'), 'sanctum')
            ->postJson('/api/device-tokens', ['token' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);
    }
}
