<?php

namespace App\Support;

use App\Models\Applicant;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryAdjustmentRequest;
use App\Models\DeliveryNotification;
use App\Models\DeliveryProof;
use App\Models\EmployeeMemo;
use App\Models\InventoryAlert;
use App\Models\KioskDevice;
use App\Models\LeaveRequest;
use App\Models\PaymentProof;
use App\Models\Project;
use App\Models\Receipt;
use App\Models\StockMovement;
use App\Models\StockOutRequest;
use App\Models\SupportMessage;
use App\Models\SupportReply;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// The one source of the "waiting" counts (owner, 2026-11-12): the blue badges
// in the side menu and the bottom tabs, and the bell in the top bar. Keyed by
// the page path the menu links to (lib/nav.jsx, lib/mobileUi.js). They are
// sent with every live-feed poll (LiveFeedController), so they update within
// seconds and go down as soon as the item is handled.
//
// Two kinds:
//   to do   items in a state that waits for this role (a pending request, a
//           Waiting delivery...). They go down when handled.
//   news    for people who only get answers (Maintenance, the customer, an
//           employee's leave): what changed since they last opened that page
//           (nav_seen). They go down when the page is opened (POST
//           nav-counts/seen). SEEN_PATHS lists these.
class NavCounts
{
    // News is counted back this far at most for someone who never opened the page.
    private const NEWS_DAYS = 14;

    public static function for(User $user): array
    {
        $counts = match (true) {
            $user->role === 'admin' => self::admin(),
            $user->role === 'hr' => self::hr($user),
            $user->role === 'driver' => self::driver($user),
            $user->role === 'customer' => self::customer($user),
            $user->role === 'employee' && $user->sub_role === 'checker' => self::checker() + self::leave($user),
            $user->role === 'employee' && $user->sub_role === 'salesman' => self::salesman($user) + self::leave($user),
            $user->role === 'employee' && $user->sub_role === 'maintenance' => self::maintenance($user) + self::leave($user),
            default => [],
        };

        return array_map('intval', $counts);
    }

    // Pages whose count is news (it goes down when the page is opened).
    public static function seenPaths(User $user): array
    {
        return match (true) {
            $user->role === 'customer' => ['/customer/deliveries', '/customer/delivery-proofs', '/customer/support'],
            $user->role === 'driver' => ['/my-leave'],
            $user->role === 'employee' && $user->sub_role === 'maintenance' => ['/maintenance/stock-out-request', '/maintenance/projects', '/my-leave'],
            $user->role === 'employee' => ['/my-leave'],
            default => [],
        };
    }

    public static function markSeen(User $user, string $path): bool
    {
        if (! in_array($path, self::seenPaths($user), true)) {
            return false;
        }
        DB::table('nav_seen')->updateOrInsert(
            ['user_id' => $user->id, 'path' => $path],
            ['seen_at' => now(), 'updated_at' => now(), 'created_at' => now()]
        );

        return true;
    }

    private static function admin(): array
    {
        return [
            // waiting for approval, and approved ones waiting for release (Admin only)
            '/admin/stock-out-approvals' => StockOutRequest::whereIn('status', ['pending', 'approved'])->count(),
            // Formal memos HR endorsed, waiting for approval
            '/admin/memo-approvals' => EmployeeMemo::where('status', 'for_approval')->count(),
            // Waiting deliveries (no driver) and customers' requests to change sacks
            '/admin/deliveries' => Delivery::where('status', 'queued')->count()
                + DeliveryAdjustmentRequest::where('status', 'pending')->count(),
            // Proofs of payment to review
            '/admin/receipts' => PaymentProof::where('status', 'pending')->count(),
            // New tickets, plus escalated ones still being worked on
            '/admin/support-inbox' => SupportMessage::where('status', 'open')
                ->orWhere(fn ($q) => $q->whereNotNull('escalated_at')->where('status', 'in_progress'))
                ->count(),
        ];
    }

    private static function hr(User $user): array
    {
        return [
            '/hr/leave-requests' => LeaveRequest::where('status', 'pending')->count(),
            // Pending applicants with an interview still ahead
            '/hr/interviews' => Applicant::where('status', 'pending')
                ->whereNotNull('interview_date')
                ->where('interview_date', '>=', now()->startOfDay())
                ->count(),
            // Draft memos (missed Time Outs) for HR to review
            '/hr/memos' => EmployeeMemo::where('status', 'draft')->count(),
            // Kiosks locked after wrong PINs
            '/hr/kiosk-pin' => KioskDevice::whereNotNull('locked_at')->count(),
        ];
    }

    private static function checker(): array
    {
        $today = DriverAvailability::today();

        return [
            // Today's (and older) deliveries not counted yet
            '/checker/ice-loading' => Delivery::where('status', 'pending')
                ->whereDate('scheduled_date', '<=', $today)
                ->whereNotIn('id', DB::table('ice_loadings')->select('delivery_id'))
                ->count(),
            // Low-stock alerts not resolved yet
            '/checker/inventory' => InventoryAlert::where('is_resolved', false)->count(),
            // Stock movements entered but not confirmed
            '/checker/stock-movements' => StockMovement::where('status', 'pending')->count(),
        ];
    }

    private static function salesman(User $user): array
    {
        $crew = DeliveryRoute::crewFor($user);
        $open = $crew
            ? DeliveryRoute::todayFor($crew->driver_id)->whereIn('status', ['pending', 'in_transit'])->filter(fn ($d) => $d->proofs->isEmpty())->count()
            : 0;

        // the stops still to confirm (same list as the "To deliver" tab)
        return ['/salesman/deliveries' => $open];
    }

    private static function driver(User $user): array
    {
        return [
            // today's stops still to do
            '/driver/deliveries' => Delivery::where('driver_id', $user->id)
                ->whereIn('status', ['pending', 'in_transit'])
                ->whereDate('scheduled_date', DriverAvailability::today())
                ->count(),
        ] + self::leave($user);
    }

    private static function maintenance(User $user): array
    {
        return [
            // own requests answered (approved, declined, released) since last seen
            '/maintenance/stock-out-request' => StockOutRequest::where('requested_by', $user->id)
                ->where('status', '!=', 'pending')
                ->where('updated_at', '>', self::seenAt($user, '/maintenance/stock-out-request'))
                ->count(),
            // projects added or changed by someone else since last seen
            '/maintenance/projects' => Project::where('updated_at', '>', self::seenAt($user, '/maintenance/projects'))
                ->where(fn ($q) => $q->whereNull('created_by')->orWhere('created_by', '!=', $user->id)->orWhereColumn('updated_at', '>', 'created_at'))
                ->count(),
        ];
    }

    // An employee's or Driver's own leave requests answered since last seen.
    private static function leave(User $user): array
    {
        $employeeId = $user->employee?->id;

        return ['/my-leave' => $employeeId
            ? LeaveRequest::where('employee_id', $employeeId)->where('status', '!=', 'pending')
                ->where('updated_at', '>', self::seenAt($user, '/my-leave'))->count()
            : 0];
    }

    private static function customer(User $user): array
    {
        $ids = Customer::where('user_id', $user->id)->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        return [
            // Invoices still to pay, without a proof of payment waiting for review
            '/customer/receipts' => Receipt::whereIn('customer_id', $ids)
                ->whereIn('payment_status', ['unpaid', 'overdue'])
                ->whereDoesntHave('paymentProofs', fn ($q) => $q->where('status', 'pending'))
                ->count(),
            // Delivery notices (driver changed, waiting, rescheduled...) since last seen
            '/customer/deliveries' => DeliveryNotification::whereIn('customer_id', $ids)
                ->where('created_at', '>', self::seenAt($user, '/customer/deliveries'))
                ->distinct()->count('delivery_id'),
            // New delivery proofs since last seen
            '/customer/delivery-proofs' => DeliveryProof::whereHas('delivery', fn ($q) => $q->whereIn('customer_id', $ids))
                ->where('created_at', '>', self::seenAt($user, '/customer/delivery-proofs'))
                ->count(),
            // Replies from the company on the customer's tickets since last seen
            '/customer/support' => SupportReply::where('kind', 'reply')
                ->where('user_id', '!=', $user->id)
                ->whereHas('ticket', fn ($q) => $q->where('user_id', $user->id))
                ->where('created_at', '>', self::seenAt($user, '/customer/support'))
                ->count(),
        ];
    }

    // When the user last opened $path (at most NEWS_DAYS back).
    private static function seenAt(User $user, string $path): string
    {
        $seen = DB::table('nav_seen')->where('user_id', $user->id)->where('path', $path)->value('seen_at');
        $floor = now()->subDays(self::NEWS_DAYS);

        return (string) ($seen && $seen > $floor->toDateTimeString() ? $seen : $floor->toDateTimeString());
    }
}
