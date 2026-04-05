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
    Schema::create('delivery_proofs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
        $table->foreignId('uploaded_by')->constrained('users');
        $table->string('photo_path');
        $table->decimal('latitude', 10, 7)->nullable();
        $table->decimal('longitude', 10, 7)->nullable();
        $table->boolean('is_within_geofence')->default(false);
        $table->integer('backload_quantity')->default(0);
        $table->text('backload_notes')->nullable();
        $table->text('notes')->nullable();
        $table->timestamp('captured_at')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_proofs');
    }
};
