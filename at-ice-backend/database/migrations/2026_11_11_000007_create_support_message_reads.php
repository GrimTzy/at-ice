<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When each Admin last opened each support ticket, so the inbox marks a ticket
// "New" until that Admin has seen the customer's latest message, on any device
// (QA review, 2026-11-11).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['support_message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_message_reads');
    }
};
