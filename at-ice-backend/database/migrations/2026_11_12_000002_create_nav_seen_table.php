<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When a user last opened a page whose badge is news (App\Support\NavCounts:
// Maintenance, the customer, an employee's own leave). Opening it sets seen_at
// and the badge goes back to 0.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nav_seen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path', 100);
            $table->timestamp('seen_at');
            $table->timestamps();
            $table->unique(['user_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_seen');
    }
};
