<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ice on each truck (owner, 2026-11-09). The trucks are refrigerated and do not
// go back to the plant, so what is not delivered stays on the truck for the
// next trip. Each loading count is a batch with its own time; a sale takes the
// oldest batch first (FIFO).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('truck_stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->string('product_type', 20);
            $table->unsignedInteger('quantity_loaded');
            $table->unsignedInteger('quantity_left');
            $table->dateTime('loaded_at');
            $table->foreignId('ice_loading_id')->nullable()->constrained('ice_loadings')->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained('deliveries')->nullOnDelete();
            $table->timestamps();

            $table->index(['driver_id', 'product_type', 'quantity_left']);
        });

        // Which batch each delivered quantity came from. A row with no batch is
        // ice delivered that no loading count covered.
        Schema::create('truck_stock_draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->nullable()->constrained('truck_stock_batches')->nullOnDelete();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->string('product_type', 20);
            $table->unsignedInteger('quantity');
            $table->dateTime('drawn_at');
            $table->timestamps();

            $table->index('delivery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('truck_stock_draws');
        Schema::dropIfExists('truck_stock_batches');
    }
};
