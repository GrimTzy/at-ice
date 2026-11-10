<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Client decisions (2026-11-10):
//   - the plant is at 14.7502926, 121.0739105 (Google Maps pin)
//   - trucks are loaded full every trip and the crew sells out the load, so no
//     ice stays on a truck for the next trip: rolling stock is switched off
//     (the truck_stock tables are kept)
// Written as stored values so they also replace an older saved value.
return new class extends Migration
{
    private const VALUES = [
        'plant.latitude' => 14.7502926,
        'plant.longitude' => 121.0739105,
        'delivery.allow_rolling_stock_fifo' => false,
    ];

    public function up(): void
    {
        foreach (self::VALUES as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()]
            );
        }
        Cache::forget('app-settings');
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_keys(self::VALUES))->delete();
        Cache::forget('app-settings');
    }
};
