<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// HR's own override PIN (owner, 2026-11-10): typed on a locked kiosk like the
// Admin override PIN, which stays. Set by HR on HRIS > Kiosk PIN.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosk_settings', function (Blueprint $table) {
            $table->string('hr_override_pin_hash')->nullable()->after('admin_pin_changed_at');
            $table->timestamp('hr_override_pin_changed_at')->nullable()->after('hr_override_pin_hash');
        });
    }

    public function down(): void
    {
        Schema::table('kiosk_settings', function (Blueprint $table) {
            $table->dropColumn(['hr_override_pin_hash', 'hr_override_pin_changed_at']);
        });
    }
};
