<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One store on a route, in delivery order. Tenants of one mall share a stop:
// the same stop_order and stop_group (e.g. "SM City San Jose del Monte").
class RouteStop extends Model
{
    protected $fillable = ['route_id', 'customer_id', 'stop_order', 'stop_group'];

    protected $casts = ['stop_order' => 'integer'];

    public function route()
    {
        return $this->belongsTo(DeliveryRoute::class, 'route_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    // customer id => [route_id, driver_id, driver_from, driver_to,
    // previous_driver_id, previous_driver_to, cover_driver_id, cover_from, cover_to, stop_order, stop_group,
    // route_code] for active routes (who drives on a day:
    // DeliveryRoute::resolveDriver)
    public static function forCustomers(array $customerIds): array
    {
        if (! $customerIds) {
            return [];
        }

        return self::query()
            ->join('delivery_routes', 'delivery_routes.id', '=', 'route_stops.route_id')
            ->where('delivery_routes.is_active', true)
            ->whereIn('route_stops.customer_id', $customerIds)
            ->get(['route_stops.customer_id', 'route_stops.route_id', 'route_stops.stop_order', 'route_stops.stop_group', 'delivery_routes.driver_id', 'delivery_routes.driver_from', 'delivery_routes.driver_to', 'delivery_routes.previous_driver_id', 'delivery_routes.previous_driver_to', 'delivery_routes.cover_driver_id', 'delivery_routes.cover_from', 'delivery_routes.cover_to', 'delivery_routes.code'])
            ->mapWithKeys(fn ($r) => [$r->customer_id => [
                'route_id' => (int) $r->route_id,
                'driver_id' => $r->driver_id ? (int) $r->driver_id : null,
                'driver_from' => $r->driver_from ? substr((string) $r->driver_from, 0, 10) : null,
                'driver_to' => $r->driver_to ? substr((string) $r->driver_to, 0, 10) : null,
                'previous_driver_id' => $r->previous_driver_id ? (int) $r->previous_driver_id : null,
                'previous_driver_to' => $r->previous_driver_to ? substr((string) $r->previous_driver_to, 0, 10) : null,
                'cover_driver_id' => $r->cover_driver_id ? (int) $r->cover_driver_id : null,
                'cover_from' => $r->cover_from ? substr((string) $r->cover_from, 0, 10) : null,
                'cover_to' => $r->cover_to ? substr((string) $r->cover_to, 0, 10) : null,
                'stop_order' => (int) $r->stop_order,
                'stop_group' => $r->stop_group,
                'route_code' => $r->code,
            ]])
            ->all();
    }
}
