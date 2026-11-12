<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Holiday and overtime pay follow the DOLE minimums from the pay period that
// started Nov 9, 2026 (QA review, 2026-11-11). Client rates that are already
// higher stay (special working day 1.30). Each rate is a new row from that date,
// so payrolls before it keep the rates they were made with.
return new class extends Migration
{
    private const FROM = '2026-11-09';

    private const RATES = [
        ['OT_ORDINARY', 1.25, 'DOLE 1.25 (Labor Code Art. 87)'],
        ['REGULAR_HOLIDAY_UNWORKED', 1.00, 'DOLE 1.00 (Art. 94), when at work or on paid leave the work day before'],
        ['OT_SPECIAL_HOLIDAY', 1.69, 'DOLE 1.69 (1.30 x 1.30)'],
        ['OT_REGULAR_HOLIDAY', 2.60, 'DOLE 2.60 (2.00 x 1.30)'],
        ['REGULAR_HOLIDAY_REST_DAY', 2.60, 'DOLE 2.60 (2.00 x 1.30)'],
        ['DOUBLE_HOLIDAY', 3.00, 'DOLE 3.00'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::RATES as [$key, $value, $note]) {
            DB::table('pay_multipliers')->updateOrInsert(
                ['key' => $key, 'effective_date' => self::FROM],
                ['value' => $value, 'note' => $note, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('pay_multipliers')->where('effective_date', self::FROM)
            ->whereIn('key', array_column(self::RATES, 0))->delete();
    }
};
