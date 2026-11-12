<?php

namespace Tests\Feature;

use App\Models\KioskDevice;
use App\Models\KioskSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Wrong Admin override PINs are limited per network address for an hour,
// across every kiosk, like the HR PIN unlock.
class KioskOverrideLimitTest extends TestCase
{
    use RefreshDatabase;

    private function lockedDevice(string $deviceId): KioskDevice
    {
        $device = KioskDevice::for($deviceId, '127.0.0.1');
        $device->forceFill(['locked_at' => now(), 'failed_attempts' => 5])->save();

        return $device;
    }

    private function override(string $deviceId, string $pin)
    {
        return $this->postJson('/api/kiosk/override', ['device_id' => $deviceId, 'admin_pin' => $pin]);
    }

    public function test_a_correct_admin_pin_still_unlocks_the_kiosk(): void
    {
        KioskSetting::current()->setAdminPin('4321');
        $device = $this->lockedDevice('kiosk-aaaaaaaaaaaaaaaa');

        $this->override($device->device_id, '0000')->assertStatus(422);
        $this->override($device->device_id, '4321')->assertOk();
        $this->assertNull($device->fresh()->locked_at);
    }

    public function test_wrong_admin_pins_block_the_address_for_an_hour(): void
    {
        KioskSetting::current()->setAdminPin('4321');

        // A new device id for each try must not reset the count.
        for ($i = 0; $i < 5; $i++) {
            $this->override('kiosk-'.str_repeat((string) $i, 16), '0000')->assertStatus(422);
        }

        $device = $this->lockedDevice('kiosk-bbbbbbbbbbbbbbbb');
        $this->override($device->device_id, '4321')
            ->assertStatus(403)
            ->assertJsonPath('code', 'kiosk_override_ip_blocked')
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'Too many wrong Admin PINs'))->etc());
        $this->assertNotNull($device->fresh()->locked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kiosk.override_ip_blocked']);

        // Still blocked a minute later (past the route throttle), free after the hour.
        $this->travel(2)->minutes();
        $this->override($device->device_id, '4321')->assertStatus(403);

        $this->travel(61)->minutes();
        $this->override($device->device_id, '4321')->assertOk();
        $this->assertNull($device->fresh()->locked_at);
    }
}
