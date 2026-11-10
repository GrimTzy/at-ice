<?php

namespace App\Models;

use App\Support\DriverAvailability;
use Illuminate\Database\Eloquent\Model;

// A fixed delivery route (A, B, C...) with its regular Driver (client,
// 2026-11-10). Its stores are in route_stops, in delivery order. Not to be
// mixed up with App\Support\DeliveryRoute (today's stops of one Driver).
//
// Who drives it on a day (owner, 2026-11-11, Driver Stores):
//   cover_driver_id   a temporary cover for one day or one week
//                     (cover_from..cover_to); on those days only
//   driver_id         the regular Driver, from driver_from (empty: any day);
//                     before driver_from the previous regular
//                     (previous_driver_id) still drives it
// When a cover ends, the regular Driver is back on his own: nothing is
// changed, the cover's dates just no longer apply.
// The code (A, B... Z, AA, AB...) is made by the system and never changes.
class DeliveryRoute extends Model
{
    protected $fillable = [
        'code', 'name', 'driver_id', 'driver_from', 'previous_driver_id',
        'cover_driver_id', 'cover_from', 'cover_to', 'is_active', 'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'driver_from' => 'date:Y-m-d',
        'cover_from' => 'date:Y-m-d',
        'cover_to' => 'date:Y-m-d',
    ];

    // The route's Driver on $date (Y-m-d), or null.
    public function driverOn(string $date): ?int
    {
        return self::resolveDriver([
            'driver_id' => $this->driver_id,
            'driver_from' => $this->driver_from?->toDateString(),
            'previous_driver_id' => $this->previous_driver_id,
            'cover_driver_id' => $this->cover_driver_id,
            'cover_from' => $this->cover_from?->toDateString(),
            'cover_to' => $this->cover_to?->toDateString(),
        ], $date);
    }

    // Same rule on a plain row (RouteStop::forCustomers), dates as Y-m-d.
    public static function resolveDriver(array $r, string $date): ?int
    {
        if (! empty($r['cover_driver_id']) && $r['cover_from'] && $r['cover_to']
            && $r['cover_from'] <= $date && $date <= $r['cover_to']) {
            return (int) $r['cover_driver_id'];
        }
        if (! empty($r['driver_from']) && $date < $r['driver_from']) {
            return empty($r['previous_driver_id']) ? null : (int) $r['previous_driver_id'];
        }

        return empty($r['driver_id']) ? null : (int) $r['driver_id'];
    }

    // driver id => route code, for who drives which active route on $date.
    public static function codesOn(string $date): array
    {
        $out = [];
        foreach (self::where('is_active', true)->get() as $r) {
            if ($id = $r->driverOn($date)) {
                $out[$id] = $r->code;
            }
        }

        return $out;
    }

    // A cover that is running or still to come.
    public function hasCover(): bool
    {
        return $this->cover_driver_id && $this->cover_to && $this->cover_to->toDateString() >= DriverAvailability::today();
    }

    // Routes $driverId drives from today on: as the regular Driver (or the
    // regular one still driving until a new one starts) or as a cover that
    // has not ended.
    public function scopeDrivenBy($q, int $driverId)
    {
        $today = DriverAvailability::today();

        return $q->where(fn ($w) => $w->where('driver_id', $driverId)
            ->orWhere(fn ($p) => $p->where('previous_driver_id', $driverId)->whereDate('driver_from', '>', $today))
            ->orWhere(fn ($c) => $c->where('cover_driver_id', $driverId)->whereDate('cover_to', '>=', $today)));
    }

    // The next free code: A to Z, then AA, AB... (like spreadsheet columns).
    // Codes of inactive routes stay taken.
    public static function nextCode(): string
    {
        $taken = array_flip(self::pluck('code')->all());
        for ($n = 1; ; $n++) {
            $code = self::letters($n);
            if (! isset($taken[$code])) {
                return $code;
            }
        }
    }

    // 1 => A, 26 => Z, 27 => AA, 28 => AB...
    public static function letters(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + $n % 26).$s;
            $n = intdiv($n, 26);
        }

        return $s;
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function previousDriver()
    {
        return $this->belongsTo(User::class, 'previous_driver_id');
    }

    public function coverDriver()
    {
        return $this->belongsTo(User::class, 'cover_driver_id');
    }

    public function stops()
    {
        return $this->hasMany(RouteStop::class, 'route_id')->orderBy('stop_order')->orderBy('id');
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class, 'route_id');
    }
}
