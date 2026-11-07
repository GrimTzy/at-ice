<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Performance history must not change when records or Settings change later.
// Publishing now stores the effective rating and the numbers behind it (the
// attendance, punctuality, goal and score the rating was worked out from).
// Reviews published before this have no snapshot; the history recalculates
// them from the records that exist and labels them as recalculated.
// final_rating keeps its meaning: Admin's optional manual override.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->decimal('published_rating', 3, 1)->nullable()->after('final_rating'); // effective rating at publication
            $table->json('snapshot')->nullable()->after('published_rating');
            $table->timestamp('snapshot_at')->nullable()->after('snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('performance_reviews', function (Blueprint $table) {
            $table->dropColumn(['published_rating', 'snapshot', 'snapshot_at']);
        });
    }
};
