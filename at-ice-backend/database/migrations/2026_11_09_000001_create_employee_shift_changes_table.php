<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Every change of an employee's shift hours, so the agreed schedule is never
// lost: the first row's from_* is the schedule the employee started with.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_shift_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('from_start', 5)->nullable();
            $table->string('from_end', 5)->nullable();
            $table->string('to_start', 5);
            $table->string('to_end', 5);
            $table->date('effective_date');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_shift_changes');
    }
};
