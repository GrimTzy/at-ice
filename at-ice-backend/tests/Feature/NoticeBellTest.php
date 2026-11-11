<?php

namespace Tests\Feature;

use App\Models\LiveEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The notice bell (QA review, 2026-11-11): notices of the last week stay listed
// for the people they reached until read; a user's own actions are left out.
class NoticeBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_notices_stay_until_read_and_reach_only_their_audience(): void
    {
        $checker = User::factory()->create(['role' => 'employee', 'sub_role' => 'checker']);
        $salesman = User::factory()->create(['role' => 'employee', 'sub_role' => 'salesman']);
        $admin = User::factory()->create(['role' => 'admin']);

        LiveEvent::create(['module' => 'inventory', 'action' => 'updated', 'message' => 'Item Ice Block 50 kg limits set by Lorna', 'roles' => ['employee:checker'], 'user_ids' => [], 'actor_id' => $admin->id]);
        LiveEvent::create(['module' => 'inventory', 'action' => 'created', 'message' => 'Item Brake Pad added by me', 'roles' => ['employee:checker'], 'user_ids' => [], 'actor_id' => $checker->id]);
        $old = LiveEvent::create(['module' => 'leave', 'action' => 'created', 'message' => 'Old notice', 'roles' => ['employee:checker'], 'user_ids' => []]);
        $old->forceFill(['created_at' => now()->subDays(8)])->save();

        Sanctum::actingAs($checker);
        $res = $this->getJson('/api/notices')->assertOk();
        $this->assertSame(1, $res->json('unread'));                         // not its own, not older than a week
        $this->assertSame(['Item Ice Block 50 kg limits set by Lorna'], array_column($res->json('notices'), 'message'));

        $this->postJson('/api/notices/read')->assertOk();
        $this->assertSame(0, $this->getJson('/api/notices')->json('unread'));
        $this->assertCount(1, $this->getJson('/api/notices')->json('notices')); // still listed, now read

        Sanctum::actingAs($salesman);
        $this->assertSame(0, $this->getJson('/api/notices')->json('unread')); // not for the Salesman
    }
}
