<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('deliveries', function (Blueprint $table) {
        $table->id();
        $table->string('delivery_no')->unique();
        $table->foreignId('driver_id')->constrained('users');
        $table->foreignId('customer_id')->nullable()->constrained('users');
        $table->enum('delivery_type', ['outbound', 'inbound'])->default('outbound');
        $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
        $table->enum('status', ['pending', 'in_transit', 'delivered', 'cancelled'])->default('pending');
        $table->date('scheduled_date');
        $table->timestamp('dispatched_at')->nullable();
        $table->timestamp('delivered_at')->nullable();
        $table->text('notes')->nullable();
        $table->foreignId('prepared_by')->constrained('users');
        $table->timestamps();
        $table->softDeletes();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
