<?php

namespace App\Support;

use App\Models\Delivery;
use App\Models\User;
use Carbon\Carbon;

// Which Drivers can be given a delivery, worked out from the records (nobody
// types it in):
//   available    timed in today at the Attendance Station, not timed out,
//                and not on a delivery that is in transit
//   in_transit   has a delivery in transit (shown, not selectable)
//   unavailable  not timed in yet today, timed out, or no active employee
//                record (shown, not selectable)
// Each row also has open_stops (today's stops not done yet) and, for an
// available Driver with none left, trip_completed_at (see tripCompletedAt).
// Reserve drivers (a Driver account with users.is_reserve_driver, owner
// 2026-11-11) are in the list with is_reserve true. They only take an absent
// Driver's whole route (DeliveryDispatch), never the FIFO queue.
// The rule applies to deliveries for today. A delivery for a later day can
// go to any active Driver, since nobody has timed in for that day yet.
class DriverAvailability
{
    // The business time zone is config('atice.timezone'); kiosk times are stored in it.
    public static function tz(): string
    {
        return config('atice.timezone');
    }

    public static function today(): string
    {
        return Carbon::now(self::tz())->toDateString();
    }

    // Is the rule checked for a delivery on this date?
    public static function enforcedFor(?string $date): bool
    {
        return ! $date || Carbon::parse($date)->toDateString() <= self::today();
    }

    public static function all(): array
    {
        $today = self::today();

        $drivers = User::where('role', 'driver')
            ->with(['employee' => fn ($q) => $q->with(['attendances' => fn ($a) => $a->whereDate('date', $today)])])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'is_reserve_driver']);

        $inTransit = Delivery::where('status', 'in_transit')
            ->whereIn('driver_id', $drivers->pluck('id'))
            ->get(['id', 'driver_id', 'delivery_no'])
            ->groupBy('driver_id');

        // Today's list of each Driver: stops still open and the last one done
        $todays = Delivery::whereIn('driver_id', $drivers->pluck('id'))
            ->whereDate('scheduled_date', $today)
            ->whereIn('status', ['pending', 'in_transit', 'delivered'])
            ->get(['id', 'driver_id', 'status', 'delivered_at'])
            ->groupBy('driver_id');

        return $drivers->map(function ($u) use ($inTransit, $todays, $today) {
            $emp = $u->employee;
            $att = $emp?->attendances->first();
            $trip = $inTransit->get($u->id)?->first();
            $list = $todays->get($u->id) ?? collect();
            $openStops = $list->whereIn('status', ['pending', 'in_transit'])->count();

            if (! $emp || ! $emp->is_active) {
                [$status, $reason] = ['unavailable', 'No active employee record'];
            } elseif ($trip) {
                [$status, $reason] = ['in_transit', "On delivery {$trip->delivery_no}"];
            } elseif (! $att || ! $att->time_in) {
                [$status, $reason] = ['unavailable', 'Not timed in yet'];
            } elseif ($att->time_out) {
                [$status, $reason] = ['unavailable', 'Timed out'];
            } else {
                [$status, $reason] = ['available', 'Timed in '.substr((string) $att->time_in, 0, 5)];
            }

            return [
                'id' => $u->id,
                'name' => $emp?->full_name ?: $u->name,
                'employee_id' => $emp?->employee_id,
                'is_reserve' => (bool) $u->is_reserve_driver,
                'status' => $status,
                'reason' => $reason,
                'open_stops' => $openStops,
                'trip_completed_at' => $status === 'available' && $openStops === 0
                    ? self::tripCompletedAt($list, $today, (string) $att->time_in)
                    : null,
            ];
        })->values()->all();
    }

    // When an available Driver finished today's list: the last stop delivered,
    // or the time in when there was nothing to deliver. The trucks stay out
    // (refrigerated, no trip back to the plant), so the dispatch queue goes by
    // this time: the first one done gets the next urgent order (FIFO).
    private static function tripCompletedAt($list, string $today, string $timeIn): string
    {
        $last = $list->where('status', 'delivered')->max('delivered_at');
        $at = $last
            ? Carbon::parse($last)->setTimezone(self::tz())
            : Carbon::parse("{$today} {$timeIn}", self::tz());

        return $at->format('Y-m-d H:i:s');
    }

    // Null when this Driver may take a delivery on $date, otherwise why not.
    public static function refusal(int $driverId, ?string $date): ?string
    {
        $user = User::find($driverId);
        if (! $user || $user->role !== 'driver') {
            return 'Please select a driver.';
        }
        if (! self::enforcedFor($date)) {
            return null;
        }

        $row = collect(self::all())->firstWhere('id', $driverId);

        return match ($row['status'] ?? 'unavailable') {
            'available' => null,
            'in_transit' => "{$row['name']} is in transit ({$row['reason']}). Pick an available driver.",
            default => "{$row['name']} is unavailable (".strtolower($row['reason'] ?? 'not timed in yet').'). Pick a driver who has timed in today.',
        };
    }
}
