<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A database that ran the earlier route design (delivery_routes + route_stops)
// keeps its routes: each one becomes a store route (name, regular Driver, the
// dates the Driver applies) and every store on it is put on that route
// (customers.store_route_id). Safe to run on any database:
//   - nothing happens when the old tables are not there (a fresh database);
//   - a route whose name already exists is reused, never duplicated;
//   - a store that is already on a store route is left where it is.
// The old tables are not dropped: they stay as a backup until the owner
// confirms the new routes are right. Stop order, the previous Driver and
// one-week covers are not part of the store route design and are not copied.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_routes') || ! Schema::hasTable('route_stops')
            || ! Schema::hasTable('store_routes') || ! Schema::hasColumn('customers', 'store_route_id')) {
            return;
        }

        $hasFrom = Schema::hasColumn('delivery_routes', 'driver_from');
        $hasTo = Schema::hasColumn('delivery_routes', 'driver_to');

        DB::transaction(function () use ($hasFrom, $hasTo) {
            foreach (DB::table('delivery_routes')->orderBy('id')->get() as $old) {
                $name = trim((string) $old->name) !== '' ? trim((string) $old->name) : 'Route '.$old->code;
                $name = mb_substr($name, 0, 60);

                $routeId = DB::table('store_routes')->where('name', $name)->value('id');
                if (! $routeId) {
                    $routeId = DB::table('store_routes')->insertGetId([
                        'name' => $name,
                        'driver_id' => $old->driver_id,
                        'effective_from' => $hasFrom ? $old->driver_from : null,
                        'effective_to' => $hasTo ? $old->driver_to : null,
                        'created_at' => $old->created_at ?? now(),
                        'updated_at' => now(),
                    ]);
                }

                $customerIds = DB::table('route_stops')->where('route_id', $old->id)->pluck('customer_id');
                DB::table('customers')
                    ->whereIn('id', $customerIds)
                    ->whereNull('store_route_id')
                    ->update(['store_route_id' => $routeId]);
            }
        });
    }

    // The conversion only adds rows to the new tables; the old ones are untouched.
    public function down(): void
    {
        //
    }
};
