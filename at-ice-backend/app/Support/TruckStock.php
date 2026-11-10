<?php

namespace App\Support;

use App\Models\Delivery;
use App\Models\IceLoading;
use App\Models\TruckStockBatch;
use App\Models\TruckStockDraw;

// Ice on the trucks, FIFO (owner, 2026-11-09). The trucks are refrigerated and
// do not go back to the plant:
//   load()    a Checker's loading count adds a batch to the Driver's truck,
//             stamped with the time it was loaded
//   draw()    a confirmed delivery takes its quantities from that truck, the
//             oldest batch first, so yesterday's ice goes out before today's
//   onTruck() what is left on each truck, oldest batch first
// Nothing is zeroed when a trip ends: what is left stays on the truck.
// HR turns it off with delivery.allow_rolling_stock_fifo (Rates and rules).
class TruckStock
{
    public static function enabled(): bool
    {
        return (bool) Settings::get('delivery.allow_rolling_stock_fifo');
    }

    // One batch per product of a loading count, on the truck of the
    // delivery's Driver (none when the delivery has no Driver yet).
    public static function load(Delivery $delivery, iterable $loadings): void
    {
        if (! $delivery->driver_id || ! self::enabled()) {
            return;
        }
        foreach ($loadings as $row) {
            /** @var IceLoading $row */
            TruckStockBatch::create([
                'driver_id' => $delivery->driver_id,
                'product_type' => $row->product_type,
                'quantity_loaded' => $row->quantity_loaded,
                'quantity_left' => $row->quantity_loaded,
                'loaded_at' => $row->loaded_at,
                'ice_loading_id' => $row->id,
                'delivery_id' => $delivery->id,
            ]);
        }
    }

    // Takes each delivered product from the Driver's truck, oldest batch
    // first. Run inside the confirmation's transaction. A quantity no batch
    // covers is still recorded, with no batch, so the totals always match.
    public static function draw(Delivery $delivery, array $lines): void
    {
        if (! $delivery->driver_id || ! self::enabled()) {
            return;
        }
        foreach ($lines as $line) {
            $need = (int) $line['quantity'];
            $batches = TruckStockBatch::where('driver_id', $delivery->driver_id)
                ->where('product_type', $line['product_type'])
                ->where('quantity_left', '>', 0)
                ->orderBy('loaded_at')->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($batches as $batch) {
                if ($need <= 0) {
                    break;
                }
                $take = min($need, $batch->quantity_left);
                $batch->decrement('quantity_left', $take);
                self::record($delivery, $line['product_type'], $take, $batch->id);
                $need -= $take;
            }
            if ($need > 0) {
                self::record($delivery, $line['product_type'], $need, null);
            }
        }
    }

    // Driver id => batches still holding ice, oldest first:
    // [{product_type, quantity_left, loaded_at}]
    public static function onTruck(array $driverIds): array
    {
        return TruckStockBatch::whereIn('driver_id', $driverIds)
            ->where('quantity_left', '>', 0)
            ->orderBy('loaded_at')->orderBy('id')
            ->get(['id', 'driver_id', 'product_type', 'quantity_left', 'loaded_at'])
            ->groupBy('driver_id')
            ->map(fn ($rows) => $rows->map(fn ($b) => [
                'id' => $b->id,
                'product_type' => $b->product_type,
                'quantity_left' => $b->quantity_left,
                'loaded_at' => $b->loaded_at?->toIso8601String(),
            ])->values())
            ->all();
    }

    private static function record(Delivery $delivery, string $type, int $qty, ?int $batchId): void
    {
        TruckStockDraw::create([
            'batch_id' => $batchId,
            'delivery_id' => $delivery->id,
            'driver_id' => $delivery->driver_id,
            'product_type' => $type,
            'quantity' => $qty,
            'drawn_at' => now(),
        ]);
    }
}
