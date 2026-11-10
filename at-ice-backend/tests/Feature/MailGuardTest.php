<?php

namespace Tests\Feature;

use App\Mail\AccountCredentials;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryNotification;
use App\Models\User;
use App\Support\DeliveryDispatch;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// No email ever goes to a .invalid address (client, 2026-11-11), so Resend
// gets no bounces; real addresses still get email. Delay notices are portal
// only unless delivery.email_delay_notices is on.
class MailGuardTest extends TestCase
{
    use RefreshDatabase;

    private function sent(): int
    {
        return count(app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_mail_to_a_dot_invalid_address_is_not_sent(): void
    {
        Mail::to('alfamart-gayagaya@atice.invalid')->send(new AccountCredentials('Alfamart', 'alfamart-gayagaya@atice.invalid', 'x'));
        $this->assertSame(0, $this->sent());

        Mail::to('owner@example.com')->send(new AccountCredentials('Owner', 'owner@example.com', 'x'));
        $this->assertSame(1, $this->sent());
    }

    public function test_a_dot_invalid_copy_is_dropped_and_the_real_one_still_goes(): void
    {
        Mail::to(['real@example.com', 'dummy@atice.invalid'])->send(new AccountCredentials('X', 'real@example.com', 'x'));

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame(['real@example.com'], array_map(fn ($a) => $a->getAddress(), $messages[0]->getOriginalMessage()->getTo()));
    }

    public function test_delay_notices_are_portal_only_and_never_go_to_dot_invalid(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@atice.cloud', 'password' => 'secret-password', 'role' => 'admin']);
        $login = User::create(['name' => 'Store', 'email' => 'store@atice.invalid', 'password' => 'secret-password', 'role' => 'customer']);
        $real = Customer::create(['name' => 'Real', 'customer_code' => 'CUST-REA-001', 'email' => 'real@example.com', 'is_active' => true]);
        $dummy = Customer::create(['name' => 'Store', 'customer_code' => 'CUST-STO-001', 'email' => 'store@atice.invalid', 'user_id' => $login->id, 'is_active' => true]);
        $make = fn ($c, $n) => Delivery::create(['delivery_no' => "DEL-000{$n}", 'customer_id' => $c->id, 'delivery_type' => 'outbound', 'status' => 'queued', 'scheduled_date' => now()->toDateString(), 'prepared_by' => $admin->id]);

        DeliveryDispatch::notifyDelay($make($real, 1));
        $this->assertSame(0, $this->sent()); // portal only by default

        Settings::set('delivery.email_delay_notices', true);
        DeliveryDispatch::notifyDelay($make($real, 2));
        $this->assertSame(1, $this->sent());

        $d = $make($dummy, 3);
        DeliveryDispatch::notifyDelay($d);
        $this->assertSame(1, $this->sent());
        $this->assertSame(['portal'], DeliveryNotification::where('delivery_id', $d->id)->pluck('channel')->all());
    }
}
