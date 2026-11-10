<?php

namespace App\Support;

use App\Mail\DeliveryDelayNotice;
use App\Models\Attendance;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\Delivery;
use App\Models\DeliveryContract;
use App\Models\DeliveryNotification;
use App\Models\DeliveryRoute;
use App\Models\DriverLeave;
use App\Models\DriverStoreAssignment;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LiveEvent;
use App\Models\RouteStop;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

// Routes and the reserve driver (client, 2026-11-10): a store on a route
// (route_stops) gets the route's regular Driver; other stores keep the Driver
// Stores assignment. When a Driver is off, their route stops today go, all of
// them, to a reserve driver (a Driver account with users.is_reserve_driver)
// if the reserve has timed in, is not on another route today and the truck
// limits allow it (RouteCapacity). Otherwise the stops wait with no Driver
// (status queued, shown as "Waiting"), Admin is alerted, and Admin assigns a
// Driver or presses Reschedule (next delivery day). The store gets a portal
// notice each time. Deliveries not on a route keep the FIFO rules below.
//
// Contract deliveries (owner decision 2026-10-17):
//   generate()        an approved contract makes one delivery per store per
//                     delivery day; the Driver comes from driver_store_assignments
//   reassign()        a Driver who is off today (not timed in by
//                     delivery.not_timed_in_minutes after their shift start, an
//                     approved leave request, or an older driver_leaves mark;
//                     Admin no longer marks anyone absent, owner 2026-10-20)
//                     has their pending deliveries given to the available
//                     Drivers; when none is free they are queued
//   assignQueued()    queued deliveries go to the available Drivers
// Deliver by is a deadline (owner, 2026-11-10): every store delivery has a
// due_at, the time it must reach the store at the latest. Both go by it: the
// earliest Deliver by first, and among the same time FIFO (owner, 2026-11-09),
// the oldest order (ordered_at) first; each to the Driver who finished their
// list first (trip_completed_at in DriverAvailability). The trucks are refrigerated and stay out, so nobody
// queues at the plant: whoever is done first is next.
//   sendTransitAlerts a delivery In transit too long tells Admin once
// HR sets these in Rates and rules > Deliveries: auto_queue_on_trip_completion,
// max_transit_hours_before_alert (and allow_rolling_stock_fifo, TruckStock).
//   sendDelayNotices  a reassigned or queued delivery tells its store it may be
//                     late, delivery.delay_notice_hours before it is due
//   sendOverdueNotices a delivery past its Deliver by time and not delivered
//                     tells its store and Admin once
// Available = timed in today, not timed out, not in transit
// (App\Support\DriverAvailability) and not off. There is no scheduler: run() is
// called by the live-feed poll (every few seconds while anyone is signed in).
class DeliveryDispatch
{
    // Users (Drivers) who are off on $date => why.
    public static function offDrivers(string $date): array
    {
        $off = [];
        foreach (DriverLeave::whereDate('date', $date)->get(['driver_id', 'type']) as $l) {
            $off[$l->driver_id] = DriverLeave::TYPES[$l->type] ?? $l->type;
        }

        // An approved leave comes before "Not timed in": a Driver on leave is
        // not late, so they stay "On leave" all day.
        $employeeIds = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id');
        if ($employeeIds->isNotEmpty()) {
            foreach (Employee::whereIn('id', $employeeIds)->whereNotNull('user_id')->pluck('user_id') as $userId) {
                $off[$userId] ??= 'On leave';
            }
        }

        if ($date === DriverAvailability::today()) {
            $off += self::notTimedIn();
        }

        return $off;
    }

    // Drivers (user id => why) who have not timed in today although their
    // shift started more than delivery.not_timed_in_minutes ago: absence is
    // detected from attendance, nobody marks it.
    public static function notTimedIn(): array
    {
        static $memo = [null, []];
        $now = Carbon::now(DriverAvailability::tz());
        // kept for a minute, or until someone times in
        $key = $now->format('Y-m-d H:i').'|'.Attendance::max('id');
        if ($memo[0] === $key) {
            return $memo[1];
        }
        $minutes = (int) Settings::get('delivery.not_timed_in_minutes');
        $out = [];
        foreach (DriverAvailability::all() as $row) {
            if ($row['status'] !== 'unavailable' || $row['reason'] !== 'Not timed in yet') {
                continue;
            }
            $employee = Employee::where('user_id', $row['id'])->first();
            if (! $employee || ($employee->date_hired && $employee->date_hired->toDateString() > $now->toDateString())) {
                continue;
            }
            $start = Lateness::shiftStart($employee);
            if ($start && $now->greaterThanOrEqualTo(Carbon::parse($now->toDateString().' '.$start, DriverAvailability::tz())->addMinutes($minutes))) {
                $out[$row['id']] = 'Not timed in';
            }
        }
        $memo = [$key, $out];

        return $out;
    }

    // Driver ids who can take a delivery today, first done first. Reserve
    // drivers are left out: they only take an absent Driver's whole route
    // (reassignRoute).
    //   1. done with today's list, by trip_completed_at (earliest first)
    //   2. still with stops left, fewest stops first
    // Ties go by name, so the order never changes between two runs.
    public static function availableDrivers(array $exclude = []): array
    {
        $off = self::offDrivers(DriverAvailability::today());

        return collect(DriverAvailability::all())
            ->where('status', 'available')
            ->reject(fn ($row) => ! empty($row['is_reserve']) || isset($off[$row['id']]) || in_array($row['id'], $exclude, false))
            ->sort(fn ($a, $b) => [$a['trip_completed_at'] === null, $a['trip_completed_at'] ?? '', $a['open_stops'], $a['name']]
                <=> [$b['trip_completed_at'] === null, $b['trip_completed_at'] ?? '', $b['open_stops'], $b['name']])
            ->pluck('id')
            ->values()
            ->all();
    }

    // The pending deliveries today of a Driver who is off. Route stops go to
    // the reserve driver or wait (reassignRoute). The others, oldest order
    // first, go to the available Drivers in first-done order (one each, then
    // round again), or to the queue when nobody is free. Only today: a leave
    // on a later day is handled on that day, when attendance is known.
    public static function reassign(int $driverId, string $date): int
    {
        if ($date !== DriverAvailability::today()) {
            return 0;
        }

        $moved = 0;
        $routeIds = Delivery::where('driver_id', $driverId)
            ->whereDate('scheduled_date', $date)
            ->where('status', 'pending')
            ->whereNotNull('route_id')
            ->distinct()->pluck('route_id');
        foreach ($routeIds as $routeId) {
            $moved += self::reassignRoute((int) $routeId, $driverId, $date);
        }

        return $moved + DB::transaction(function () use ($driverId, $date) {
            $pending = Delivery::where('driver_id', $driverId)
                ->whereDate('scheduled_date', $date)
                ->where('status', 'pending')
                ->whereNull('route_id')
                ->byDeadline()
                ->lockForUpdate()
                ->get();

            $available = self::availableDrivers([$driverId]);
            foreach ($pending->values() as $i => $d) {
                $d->update($available
                    ? ['driver_id' => $available[$i % count($available)], 'reassigned_from_driver_id' => $driverId]
                    : ['driver_id' => null, 'status' => 'queued', 'reassigned_from_driver_id' => $driverId]);
            }

            return $pending->count();
        });
    }

    // Reserve drivers who can take a route today, lowest id first: timed in,
    // not off, and not already on another route today (one trip a day).
    public static function reserveDrivers(?int $forRouteId = null): array
    {
        $today = DriverAvailability::today();
        $off = self::offDrivers($today);

        return collect(DriverAvailability::all())
            ->filter(fn ($r) => ! empty($r['is_reserve'])
                && in_array($r['status'], ['available', 'in_transit'], true)
                && ! isset($off[$r['id']]))
            ->reject(fn ($r) => Delivery::where('driver_id', $r['id'])
                ->whereDate('scheduled_date', $today)
                ->whereNotNull('route_id')
                ->when($forRouteId, fn ($q) => $q->where('route_id', '!=', $forRouteId))
                ->where('status', '!=', 'cancelled')
                ->exists())
            ->sortBy('id')->pluck('id')->values()->all();
    }

    // One route's stops today, from a Driver who is off ($fromDriverId) or
    // already Waiting ($fromDriverId null): all of them to a reserve driver
    // who can take them, otherwise Waiting with an Admin alert. Each store
    // gets a portal notice.
    public static function reassignRoute(int $routeId, ?int $fromDriverId, string $date): int
    {
        return DB::transaction(function () use ($routeId, $fromDriverId, $date) {
            $stops = Delivery::with(['customer', 'items'])
                ->where('route_id', $routeId)
                ->whereDate('scheduled_date', $date)
                ->when($fromDriverId,
                    fn ($q) => $q->where('driver_id', $fromDriverId)->where('status', 'pending'),
                    fn ($q) => $q->where('status', 'queued')->whereNull('driver_id'))
                ->byDeadline()
                ->lockForUpdate()
                ->get();
            if ($stops->isEmpty()) {
                return 0;
            }

            $rows = $stops->map(fn ($d) => [
                'customer_id' => $d->customer_id,
                'items' => $d->items->map(fn ($i) => ['product_type' => $i->product_type, 'quantity' => (int) $i->quantity_ordered])->all(),
            ])->all();
            $reserve = collect(self::reserveDrivers($routeId))
                ->first(fn ($id) => RouteCapacity::fits((int) $id, $date, $rows));

            $route = DeliveryRoute::find($routeId);
            $routeName = $route ? $route->name : 'A route';
            $absent = $fromDriverId ? (User::whereKey($fromDriverId)->value('name') ?? 'Its driver') : null;
            $count = $stops->count();
            $stopsText = $count.' '.($count === 1 ? 'stop' : 'stops');

            if ($reserve) {
                $name = User::whereKey($reserve)->value('name');
                foreach ($stops as $d) {
                    $d->update([
                        'driver_id' => $reserve,
                        'status' => 'pending',
                        'reassigned_from_driver_id' => $d->reassigned_from_driver_id ?? $fromDriverId,
                    ]);
                    self::notifyChange($d, 'driver_changed', "Your delivery {$d->delivery_no} today will be delivered by our reserve driver {$name}.");
                }
                LiveEvent::create([
                    'module' => 'deliveries',
                    'action' => 'updated',
                    'message' => mb_substr("{$routeName}: reserve driver {$name} takes {$stopsText} today".($absent ? " ({$absent} is off)." : '.'), 0, 255),
                    'roles' => ['employee:salesman'],
                    'user_ids' => [(int) $reserve],
                    'actor_id' => null,
                ]);

                return $count;
            }

            if (! $fromDriverId) {
                return 0; // still Waiting; Admin was alerted when they started waiting
            }
            foreach ($stops as $d) {
                $d->update(['driver_id' => null, 'status' => 'queued', 'reassigned_from_driver_id' => $fromDriverId]);
                self::notifyChange($d, 'waiting', "Your delivery {$d->delivery_no} today is waiting for a driver because its driver is absent. We will tell you when it is moved.");
            }
            self::alertWaiting($routeName, (string) $absent, $stopsText, $date);

            return $count;
        });
    }

    // Admin: a route's stops are Waiting (once per route, Driver and day).
    private static function alertWaiting(string $routeName, string $absent, string $stopsText, string $date): void
    {
        if (! Cache::add('waiting-alert:'.md5("{$routeName}|{$absent}|{$date}"), 1, now()->addDays(2))) {
            return;
        }
        LiveEvent::create([
            'module' => 'deliveries',
            'action' => 'updated',
            'message' => mb_substr("{$routeName}: {$absent} is absent and no reserve driver can take the route. {$stopsText} are Waiting: assign a driver or reschedule them.", 0, 255),
            'roles' => [], // Admin sees every event
            'user_ids' => [],
            'actor_id' => null,
        ]);
    }

    // Waiting route stops today go to a reserve driver who has timed in since.
    public static function assignWaitingToReserve(): int
    {
        $today = DriverAvailability::today();
        $routeIds = Delivery::where('status', 'queued')
            ->whereNull('driver_id')
            ->whereNotNull('route_id')
            ->whereDate('scheduled_date', $today)
            ->distinct()->pluck('route_id');

        $moved = 0;
        foreach ($routeIds as $routeId) {
            $moved += self::reassignRoute((int) $routeId, null, $today);
        }

        return $moved;
    }

    // Admin presses Reschedule on a Waiting delivery: it moves to $date, or to
    // the next delivery day (the contract's next day, else the day after),
    // with the Driver of that day. The store is told. Returns the truck-limit
    // warnings of that Driver's day.
    public static function reschedule(Delivery $d, ?string $date = null): array
    {
        $from = $d->scheduledDateString();
        $date ??= self::nextDeliveryDay($d);

        $routes = $d->customer_id ? RouteStop::forCustomers([$d->customer_id]) : [];
        $driverId = $d->customer_id ? self::driverFor($d->customer_id, $date, $routes) : null;
        $time = $d->ordered_at ? $d->ordered_at->format('H:i:s') : '08:00:00';
        // the same Deliver by time, on the new day
        $dueAt = $d->due_at ? self::dueAt($date, $d->due_at->copy()->setTimezone(DriverAvailability::tz())->format('H:i:s')) : null;

        $d->update([
            'scheduled_date' => $date,
            'rescheduled_from' => $from,
            'ordered_at' => Carbon::parse("{$date} {$time}"),
            'due_at' => $dueAt,
            'driver_id' => $driverId,
            'route_id' => $routes[$d->customer_id]['route_id'] ?? null,
            'status' => $driverId ? 'pending' : 'queued',
            'reassigned_from_driver_id' => null,
        ]);
        $d->loadMissing('customer');
        self::notifyChange($d, 'rescheduled', "Your delivery {$d->delivery_no} for ".Carbon::parse($from)->format('M j').' was moved to '.Carbon::parse($date)->format('M j').' because its driver was absent.');
        Audit::log('delivery.rescheduled', $d, ['scheduled_date' => $from], ['scheduled_date' => $date, 'driver_id' => $driverId]);

        return $driverId ? RouteCapacity::warningsFor([$driverId], [$date]) : [];
    }

    // The contract's next delivery day after today (and after the delivery's
    // own day), else the day after.
    public static function nextDeliveryDay(Delivery $d): string
    {
        $after = max(DriverAvailability::today(), (string) $d->scheduledDateString());
        if ($d->contract_id && ($contract = DeliveryContract::find($d->contract_id))) {
            foreach (self::dates($contract) as $day) {
                if ($day > $after) {
                    return $day;
                }
            }
        }

        return Carbon::parse($after)->addDay()->toDateString();
    }

    // Queued deliveries today, earliest Deliver by first, then FIFO (oldest
    // ordered_at), to the available Drivers in first-done order.
    // Off with delivery.auto_queue_on_trip_completion: Admin then picks a
    // Driver for each queued delivery (Edit on the delivery).
    public static function assignQueued(): int
    {
        if (! Settings::get('delivery.auto_queue_on_trip_completion')) {
            return 0;
        }
        $today = DriverAvailability::today();

        return DB::transaction(function () use ($today) {
            $queued = Delivery::where('status', 'queued')
                ->whereNull('route_id') // route stops wait for the reserve or Admin
                ->whereDate('scheduled_date', $today)
                ->byDeadline()
                ->lockForUpdate()
                ->get();
            if ($queued->isEmpty()) {
                return 0;
            }

            $drivers = self::availableDrivers();
            if (! $drivers) {
                return 0;
            }

            foreach ($queued as $i => $d) {
                $d->update(['driver_id' => $drivers[$i % count($drivers)], 'status' => 'pending']);
            }

            return $queued->count();
        });
    }

    // Called by the live-feed poll; does the work at most every 15 seconds.
    // The poll's user is set aside while it runs, so the notices it causes go
    // to everyone concerned, the poller included.
    public static function run(): void
    {
        if (! Cache::add('delivery-dispatch-run', 1, 15)) {
            return;
        }

        $user = Auth::user();
        Auth::forgetUser();
        try {
            $today = DriverAvailability::today();
            foreach (array_keys(self::offDrivers($today)) as $driverId) {
                self::reassign((int) $driverId, $today);
            }
            self::assignWaitingToReserve();
            self::assignQueued();
            self::sendDelayNotices();
            self::sendOverdueNotices();
            self::sendTransitAlerts();
        } catch (Throwable $e) {
            Log::warning('DeliveryDispatch: '.$e->getMessage());
        } finally {
            if ($user) {
                Auth::setUser($user);
            }
        }
    }

    // A delivery In transit longer than delivery.max_transit_hours_before_alert
    // (0 = never) tells Admin once, so a truck stuck on the road is noticed.
    public static function sendTransitAlerts(): int
    {
        $hours = (int) Settings::get('delivery.max_transit_hours_before_alert');
        if ($hours <= 0) {
            return 0;
        }

        $sent = 0;
        $late = Delivery::with(['driver:id,name', 'customer:id,name'])
            ->where('status', 'in_transit')
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '<=', now()->subHours($hours))
            ->get();
        foreach ($late as $d) {
            if (! Cache::add("transit-alert:{$d->id}", 1, now()->addDays(3))) {
                continue; // told already
            }
            LiveEvent::create([
                'module' => 'deliveries',
                'action' => 'updated',
                'message' => mb_substr("Delivery {$d->delivery_no} to ".($d->customer?->name ?? 'a store').' has been in transit for more than '.$hours.' '.($hours === 1 ? 'hour' : 'hours').($d->driver ? " (driver {$d->driver->name})" : '').'.', 0, 255),
                'roles' => [], // Admin sees every event
                'user_ids' => [],
                'actor_id' => null,
            ]);
            $sent++;
        }

        return $sent;
    }

    // A reassigned or queued delivery today tells its store it may be late,
    // delivery.delay_notice_hours before its Deliver by time (an older delivery
    // with no Deliver by time: right away). Once per delivery.
    public static function sendDelayNotices(): int
    {
        $hours = (int) Settings::get('delivery.delay_notice_hours');
        $due = Delivery::with('customer')
            ->whereDate('scheduled_date', DriverAvailability::today())
            ->whereIn('status', ['pending', 'queued'])
            ->whereNotNull('reassigned_from_driver_id')
            ->whereNull('route_id') // route stops were told when they moved
            ->whereDoesntHave('notifications', fn ($q) => $q->where('kind', 'delay'))
            ->get();

        $sent = 0;
        foreach ($due as $d) {
            $sendAt = $d->due_at ? $d->due_at->copy()->subHours($hours) : now();
            if (now()->lt($sendAt)) {
                continue;
            }
            self::notifyDelay($d);
            $sent++;
        }

        return $sent;
    }

    // Every channel the store has: the customer portal (a live notice and the
    // list on its Deliveries page) and, with delivery.email_delay_notices on,
    // email (never to a .invalid address, App\Support\MailGuard). SMS needs an SMS provider, which
    // the system does not have yet. A store with no channel is marked "none".
    public static function notifyDelay(Delivery $d): void
    {
        $customer = $d->customer;
        if (! $customer) {
            return;
        }

        $when = $d->due_at ? ' (deliver by '.self::dueTime($d->due_at).')' : '';
        self::tellStore($d, 'delay', "Your delivery {$d->delivery_no} today{$when} may be delayed: it was moved to another driver.");
    }

    // A delivery today past its Deliver by time and not delivered yet (waiting,
    // queued or still on the road) tells its store and Admin once. The store
    // knows the ice is late before it has to ask.
    public static function sendOverdueNotices(): int
    {
        $late = Delivery::with(['customer', 'driver:id,name'])
            ->whereDate('scheduled_date', DriverAvailability::today())
            ->whereIn('status', ['pending', 'queued', 'in_transit'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereDoesntHave('notifications', fn ($q) => $q->where('kind', 'overdue'))
            ->get();

        foreach ($late as $d) {
            $by = self::dueTime($d->due_at);
            self::tellStore($d, 'overdue', "Your delivery {$d->delivery_no} was due by {$by} and is running late. We are on it and will let you know when it arrives.");
            LiveEvent::create([
                'module' => 'deliveries',
                'action' => 'updated',
                'message' => mb_substr("Delivery {$d->delivery_no} to ".($d->customer?->name ?? 'a store')." is past its Deliver by time ({$by})".($d->driver ? ", driver {$d->driver->name}" : ', no driver yet').'.', 0, 255),
                'roles' => [], // Admin sees every event
                'user_ids' => [],
                'actor_id' => null,
            ]);
        }

        return $late->count();
    }

    // Sends one notice to the store on every channel it has, kept once per
    // delivery and kind.
    private static function tellStore(Delivery $d, string $kind, string $message): void
    {
        $customer = $d->customer;
        if (! $customer) {
            return;
        }
        $channels = [];

        if ($customer->user_id) {
            LiveEvent::create([
                'module' => 'deliveries',
                'action' => 'updated',
                'message' => mb_substr($message, 0, 255),
                'roles' => [],
                'user_ids' => [$customer->user_id],
                'actor_id' => null,
            ]);
            $channels[] = 'portal';
        }

        if ($customer->email && Settings::get('delivery.email_delay_notices') && ! MailGuard::isUndeliverable($customer->email)) {
            try {
                Mail::to($customer->email)->send(new DeliveryDelayNotice($customer->name, $d->delivery_no, $message));
                $channels[] = 'email';
            } catch (Throwable $e) {
                Log::warning("Delay notice email for {$d->delivery_no} failed: ".$e->getMessage());
            }
        }

        foreach ($channels ?: ['none'] as $channel) {
            DeliveryNotification::firstOrCreate(
                ['delivery_id' => $d->id, 'channel' => $channel, 'kind' => $kind],
                ['customer_id' => $customer->id, 'message' => mb_substr($message, 0, 255), 'sent_at' => now()]
            );
        }
    }

    // A change the store should know about (driver changed or moved to another
    // driver, cancelled): a notice on its portal, kept in its Deliveries page.
    // Only the new driver's name is shared, nothing else about the driver.
    public static function notifyChange(Delivery $d, string $kind, string $message): void
    {
        $customer = $d->customer;
        if (! $customer) {
            return;
        }
        $message = mb_substr($message, 0, 255);
        if ($customer->user_id) {
            LiveEvent::create([
                'module' => 'deliveries',
                'action' => 'updated',
                'message' => $message,
                'roles' => [],
                'user_ids' => [$customer->user_id],
                'actor_id' => null,
            ]);
        }
        DeliveryNotification::create([
            'customer_id' => $customer->id,
            'delivery_id' => $d->id,
            'channel' => $customer->user_id ? 'portal' : 'none',
            'kind' => $kind,
            'message' => $message,
            'sent_at' => now(),
        ]);
    }

    // The Deliver by time on a day, in the company's time zone, stored the way
    // the other delivery times are.
    public static function dueAt(string $date, string $time): Carbon
    {
        return Carbon::parse("{$date} {$time}", DriverAvailability::tz())->setTimezone(config('app.timezone'));
    }

    // "8:00 AM": a Deliver by time as the store reads it, in the company's
    // time zone whatever the app's is.
    public static function dueTime(\DateTimeInterface $at): string
    {
        return Carbon::instance($at)->setTimezone(DriverAvailability::tz())->format('g:i A');
    }

    // "Nov 16" for a delivery date
    public static function day(Delivery $d): string
    {
        return $d->scheduled_date ? $d->scheduled_date->format('M j') : 'its date';
    }

    // Stores in the contract that have no price for one of its products.
    public static function missingPrices(DeliveryContract $contract): array
    {
        $missing = [];
        foreach ($contract->stores as $store) {
            foreach ($contract->items as $item) {
                if (CustomerPrice::priceFor($store->id, $item['product_type']) === null) {
                    $missing[$store->name][] = $item['product_type'];
                }
            }
        }

        return $missing;
    }

    // Dates from the later of the start date and today to the end date that
    // fall on one of the contract's delivery days.
    public static function dates(DeliveryContract $contract): array
    {
        $from = max($contract->start_date->toDateString(), DriverAvailability::today());
        $to = $contract->end_date->toDateString();
        if ($from > $to) {
            return [];
        }

        $days = $contract->delivery_days ?? [];
        $out = [];
        foreach (CarbonPeriod::create($from, $to) as $day) {
            if (in_array(strtolower($day->format('D')), $days, true)) {
                $out[] = $day->toDateString();
            }
        }

        return $out;
    }

    // The daily deliveries of an approved contract: one per store per delivery
    // day, skipping a store that already has a delivery that day. Model events
    // are off while they are made, so one notice says how many there are
    // instead of one per delivery.
    public static function generate(DeliveryContract $contract, int $preparedBy): array
    {
        $dates = self::dates($contract);
        $stores = $contract->stores()->get(['customers.id', 'customers.name']);
        if (! $dates || $stores->isEmpty()) {
            return ['created' => 0, 'skipped' => 0];
        }

        $booked = Delivery::whereIn('customer_id', $stores->pluck('id'))
            ->whereBetween('scheduled_date', [reset($dates), end($dates)])
            ->where('status', '!=', 'cancelled')
            ->where('is_top_up', false) // an urgent top-up never takes the store's day
            ->get(['customer_id', 'scheduled_date'])
            ->map(fn ($d) => $d->customer_id.'|'.$d->scheduled_date->toDateString())
            ->flip();

        $prices = [];
        foreach ($stores as $store) {
            foreach ($contract->items as $item) {
                $prices[$store->id][$item['product_type']] = CustomerPrice::priceFor($store->id, $item['product_type']);
            }
        }

        $routes = RouteStop::forCustomers($stores->pluck('id')->all());
        $time = substr((string) $contract->delivery_time, 0, 5) ?: '08:00';
        $created = 0;
        $skipped = 0;
        $drivers = [];

        Delivery::withoutEvents(function () use ($contract, $dates, $stores, $booked, $prices, $routes, $time, $preparedBy, &$created, &$skipped, &$drivers) {
            DB::transaction(function () use ($contract, $dates, $stores, $booked, $prices, $routes, $time, $preparedBy, &$created, &$skipped, &$drivers) {
                $no = self::lastDeliveryNumber();
                foreach ($dates as $date) {
                    foreach ($stores as $store) {
                        if (isset($booked[$store->id.'|'.$date])) {
                            $skipped++;

                            continue;
                        }
                        $driverId = self::driverFor($store->id, $date, $routes);
                        $delivery = Delivery::create([
                            'delivery_no' => self::formatDeliveryNo(++$no),
                            'contract_id' => $contract->id,
                            'route_id' => $routes[$store->id]['route_id'] ?? null,
                            'driver_id' => $driverId,
                            'customer_id' => $store->id,
                            'delivery_type' => 'outbound',
                            'status' => 'pending',
                            'scheduled_date' => $date,
                            'ordered_at' => Carbon::parse("{$date} {$time}"),
                            // the contract's Deliver by time on that day
                            'due_at' => self::dueAt($date, $time),
                            'notes' => "Contract {$contract->contract_no}",
                            'prepared_by' => $preparedBy,
                        ]);
                        foreach ($contract->items as $item) {
                            $delivery->items()->create([
                                'product_type' => $item['product_type'],
                                'quantity_ordered' => $item['quantity'],
                                'unit_price' => $prices[$store->id][$item['product_type']],
                            ]);
                        }
                        $created++;
                        if ($driverId) {
                            $drivers[$driverId] = true;
                        }
                    }
                }
            });
        });

        if ($created > 0) {
            LiveEvent::create([
                'module' => 'deliveries',
                'action' => 'created',
                'message' => "Contract {$contract->contract_no} ({$contract->name}) approved: {$created} deliveries scheduled",
                'roles' => ['employee:salesman', 'employee:checker'],
                'user_ids' => array_keys($drivers),
                'actor_id' => Auth::id(),
            ]);
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    // A new store assignment applies to that store's pending deliveries from
    // its first day (today at the earliest) to its last. A delivery that was
    // reassigned because of a leave keeps its new Driver.
    // $changed gets the deliveries whose Driver is now different, so the store
    // can be told once (DriverAssignmentController).
    public static function applyAssignment(DriverStoreAssignment $a, ?array &$changed = null): int
    {
        $from = max($a->effective_from->toDateString(), DriverAvailability::today());
        $q = Delivery::where('customer_id', $a->store_id)
            ->where('status', 'pending')
            ->whereNull('reassigned_from_driver_id')
            ->whereNull('route_id') // a route stop keeps the route's Driver
            ->whereDate('scheduled_date', '>=', $from);
        if ($a->effective_to) {
            $q->whereDate('scheduled_date', '<=', $a->effective_to->toDateString());
        }
        $changed = (clone $q)->where(fn ($w) => $w->whereNull('driver_id')->orWhere('driver_id', '!=', $a->driver_id))->pluck('id')->all();

        // Query update: no notice per delivery (the controller sends one).
        return $q->update(['driver_id' => $a->driver_id, 'updated_at' => now()]);
    }

    // The Driver of a store on $date: its route's Driver that day (a cover,
    // else the regular Driver, DeliveryRoute::resolveDriver), else the
    // Driver Stores assignment. $routes: RouteStop::forCustomers(), so a
    // contract does not query each store again.
    public static function driverFor(int $storeId, string $date, ?array $routes = null): ?int
    {
        $routes ??= RouteStop::forCustomers([$storeId]);

        $r = $routes[$storeId] ?? null;

        return ($r ? DeliveryRoute::resolveDriver($r, $date) : null) ?? DriverStoreAssignment::driverFor($storeId, $date);
    }

    // After a route's Driver, stores or active flag change: the upcoming
    // pending deliveries (today on) of its stores get the route and its
    // Driver, and those of stores taken off it ($removed) go back to their
    // Driver Stores assignment. A delivery moved because of an absence keeps
    // its Driver. Returns the ids whose Driver changed, so the stores are told.
    public static function applyRoute(DeliveryRoute $route, array $removed = []): array
    {
        $today = DriverAvailability::today();
        $changed = [];
        $update = function ($d, ?int $driverId, ?int $routeId) use (&$changed) {
            $old = $d->driver_id === null ? null : (int) $d->driver_id;
            if ($old !== $driverId) {
                $changed[] = $d->id;
            }
            if ($old !== $driverId || ($d->route_id === null ? null : (int) $d->route_id) !== $routeId) {
                Delivery::whereKey($d->id)->update(['driver_id' => $driverId, 'route_id' => $routeId, 'updated_at' => now()]);
            }
        };
        $upcoming = fn ($q) => $q->where('status', 'pending')
            ->whereNull('reassigned_from_driver_id')
            ->where('delivery_type', 'outbound')
            ->whereDate('scheduled_date', '>=', $today);

        $storeIds = $route->stops()->pluck('customer_id')->all();
        foreach ($upcoming(Delivery::whereIn('customer_id', $storeIds))->get(['id', 'customer_id', 'driver_id', 'route_id', 'scheduled_date']) as $d) {
            $route->is_active
                ? $update($d, $route->driverOn($d->scheduledDateString()) ?? DriverStoreAssignment::driverFor($d->customer_id, $d->scheduledDateString()), $route->id)
                : $update($d, DriverStoreAssignment::driverFor($d->customer_id, $d->scheduledDateString()), null);
        }
        if ($removed) {
            $rows = $upcoming(Delivery::whereIn('customer_id', $removed)->where('route_id', $route->id))->get(['id', 'customer_id', 'driver_id', 'route_id', 'scheduled_date']);
            foreach ($rows as $d) {
                $update($d, DriverStoreAssignment::driverFor($d->customer_id, $d->scheduledDateString()), null);
            }
        }

        return $changed;
    }

    // Highest DEL-0000 number so far. LIKE + a PHP check instead of REGEXP,
    // which SQLite (local dev) does not have. Past 9999 the numbers get longer.
    public static function lastDeliveryNumber(): int
    {
        return (int) Delivery::withTrashed()
            ->where('delivery_no', 'like', 'DEL-%')
            ->pluck('delivery_no')
            ->filter(fn ($no) => preg_match('/^DEL-\d{4,}$/', $no))
            ->map(fn ($no) => (int) substr($no, 4))
            ->max();
    }

    public static function formatDeliveryNo(int $n): string
    {
        return 'DEL-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    public static function storeIdsForChain(string $chain): array
    {
        return Customer::where('chain', $chain)->where('is_active', true)->pluck('id')->all();
    }
}
