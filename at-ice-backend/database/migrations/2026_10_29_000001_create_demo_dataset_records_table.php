<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which rows an installed dataset added (php artisan thesis-demo). Used only to
// track and safely remove that dataset; nothing in the app reads it.
// kind: created (made by the installer), derived (made by the app from dataset
// rows, e.g. a memo the MemoEngine drafted, adopted by `thesis-demo verify`).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_dataset_records', function (Blueprint $table) {
            $table->id();
            $table->string('dataset', 40);
            $table->string('table_name', 64);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('ref', 255)->nullable(); // a stored file: "disk:path"
            $table->string('scenario_key', 120);
            $table->string('kind', 10)->default('created');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['dataset', 'scenario_key']);
            $table->index(['dataset', 'table_name', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_dataset_records');
    }
};
