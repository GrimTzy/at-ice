<?php

namespace App\Support\ThesisDemo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Removes exactly the rows in the manifest, children first. Before deleting
// it looks for rows NOT in the manifest that point at dataset rows:
//   derived   made by the app or by people during the demo on dataset rows
//             only (a payroll HR approved for a fictional employee, the memo
//             MemoEngine drafted, a ticket a demo customer sent). Removed only
//             with --include-derived.
//   blockers  real records tied to dataset rows (a real store's delivery the
//             dispatcher gave a fictional driver, a real payroll that used
//             seeded attendance of an existing employee). Cleanup refuses.
class Cleanup
{
    // delete order: children before parents
    public const ORDER = [
        'support_replies', 'support_messages', 'payment_proofs', 'receipt_items', 'receipts', 'delivery_proofs', 'delivery_arrivals',
        'ice_loadings', 'delivery_notifications', 'delivery_adjustment_requests', 'delivery_adjustments', 'delivery_items', 'deliveries',
        'delivery_crews', 'delivery_contract_stores', 'delivery_contracts', 'driver_store_assignments', 'customer_prices',
        'payroll_adjustments', 'payrolls', 'employee_memo_files', 'employee_memos', 'leave_requests', 'attendances',
        'employee_documents', 'performance_reviews', 'applicants', 'stock_out_requests', 'inventory_alerts', 'stock_movements',
        'products', 'projects', 'suppliers', 'pickup_logs', 'expenses', 'customers', 'employees', 'users',
    ];

    public function __construct(private Manifest $m) {}

    private function ids(string $t): array
    {
        return $this->m->ids($t);
    }

    private function notIn($q, string $table)
    {
        $ids = $this->ids($table);

        return $ids ? $q->whereNotIn("{$table}.id", $ids) : $q;
    }

    private function count(string $table, callable $where): array
    {
        $q = DB::table($table);
        $where($q);
        $rows = $this->notIn($q, $table)->pluck('id')->all();

        return $rows;
    }

    // ['derived' => [table => ids], 'blockers' => [label => ids]]
    public function scan(bool $keepExistingAttendance = false): array
    {
        $E = $this->ids('employees');
        $U = $this->ids('users');
        $C = $this->ids('customers');
        $D = $this->ids('deliveries');
        $P = $this->ids('products');
        $PJ = $this->ids('projects');
        $in = fn (array $ids) => $ids ?: [0];

        $derived = [
            'attendances' => $this->count('attendances', fn ($q) => $q->whereIn('employee_id', $in($E))),
            'payrolls' => $this->count('payrolls', fn ($q) => $q->whereIn('employee_id', $in($E))),
            'employee_memos' => $this->count('employee_memos', fn ($q) => $q->whereIn('employee_id', $in($E))),
            'leave_requests' => $this->count('leave_requests', fn ($q) => $q->whereIn('employee_id', $in($E))),
            'face_data' => $this->count('face_data', fn ($q) => $q->whereIn('employee_id', $in($E))),
            'employee_documents' => $this->count('employee_documents', fn ($q) => $q->whereIn('employee_id', $in($E))),
            'performance_reviews' => $this->count('performance_reviews', fn ($q) => $q->whereIn('employee_id', $in($E))),
            'support_messages' => $this->count('support_messages', fn ($q) => $q->whereIn('customer_id', $in($C))),
            'payment_proofs' => $this->count('payment_proofs', fn ($q) => $q->whereIn('customer_id', $in($C))),
            'delivery_adjustment_requests' => $this->count('delivery_adjustment_requests', fn ($q) => $q->whereIn('customer_id', $in($C))),
            'customer_prices' => $this->count('customer_prices', fn ($q) => $q->whereIn('customer_id', $in($C))),
            'deliveries' => $this->count('deliveries', fn ($q) => $q->whereIn('customer_id', $in($C))),
            'delivery_crews' => $this->count('delivery_crews', fn ($q) => $q->where(fn ($w) => $w->whereIn('salesman_id', $in($U))->orWhereIn('driver_id', $in($U)))),
            'driver_store_assignments' => $this->count('driver_store_assignments', fn ($q) => $q->where(fn ($w) => $w->whereIn('store_id', $in($C))->orWhereIn('driver_id', $in($U)))),
            'stock_movements' => $this->count('stock_movements', fn ($q) => $q->whereIn('product_id', $in($P))),
            'stock_out_requests' => $this->count('stock_out_requests', fn ($q) => $q->where(fn ($w) => $w->whereIn('product_id', $in($P))->orWhereIn('project_id', $in($PJ)))),
            'inventory_alerts' => $this->count('inventory_alerts', fn ($q) => $q->whereIn('product_id', $in($P))),
            'personal_access_tokens' => DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\User')->whereIn('tokenable_id', $in($U))->pluck('id')->all(),
        ];
        $derived = array_filter($derived);

        $realDeliveries = fn ($q) => $q->where(fn ($w) => $w->whereNotIn('customer_id', $in($C))->orWhereNull('customer_id'));
        $blockers = [
            'real deliveries with a fictional driver or preparer' => $this->count('deliveries', fn ($q) => $realDeliveries($q)
                ->where(fn ($w) => $w->whereIn('driver_id', $in($U))->orWhereIn('reassigned_from_driver_id', $in($U))->orWhereIn('prepared_by', $in($U)))),
            'real receipts or proofs with a fictional salesman' => array_merge(
                $this->count('receipts', fn ($q) => $q->whereNotIn('customer_id', $in($C))->whereIn('salesman_id', $in($U))),
                $this->count('delivery_proofs', fn ($q) => $q->whereNotIn('delivery_id', $in($D))->whereIn('salesman_id', $in($U)))),
            'real arrivals or loading counts by fictional staff' => array_merge(
                $this->count('delivery_arrivals', fn ($q) => $q->whereNotIn('delivery_id', $in($D))->whereIn('user_id', $in($U))),
                $this->count('ice_loadings', fn ($q) => $q->whereNotIn('delivery_id', $in($D))->whereIn('checker_id', $in($U)))),
            'real stock records by fictional staff' => array_merge(
                $this->count('stock_movements', fn ($q) => $q->whereNotIn('product_id', $in($P))->where(fn ($w) => $w->whereIn('entered_by', $in($U))->orWhereIn('confirmed_by', $in($U)))),
                $this->count('stock_out_requests', fn ($q) => $q->where(fn ($w) => $w->whereNotIn('product_id', $in($P))->orWhereNull('product_id'))->whereIn('requested_by', $in($U)))),
            'real support tickets or replies by fictional users' => $this->count('support_replies', fn ($q) => $q->whereIn('user_id', $in($U))
                ->whereNotIn('support_message_id', $in($this->ids('support_messages')))),
        ];

        // Existing employees: a payroll for a period that holds seeded attendance
        if (! $keepExistingAttendance) {
            $seeded = DB::table('attendances')->whereIn('id', $in($this->ids('attendances')))->whereNotIn('employee_id', $in($E))
                ->get(['employee_id', 'date']);
            $hits = [];
            foreach ($seeded->groupBy('employee_id') as $emp => $rows) {
                $dates = $rows->pluck('date')->map(fn ($d) => substr($d, 0, 10));
                $hits = array_merge($hits, DB::table('payrolls')->where('employee_id', $emp)
                    ->where(fn ($q) => $dates->each(fn ($d) => $q->orWhere(fn ($w) => $w->where('period_start', '<=', $d)->where('period_end', '>=', $d))))
                    ->pluck('id')->all());
            }
            $blockers['payrolls of existing employees computed from seeded attendance (use --keep-existing-attendance)'] = array_values(array_unique($hits));
        }

        return ['derived' => $derived, 'blockers' => array_filter($blockers)];
    }

    // Returns [deleted counts] or throws when blocked.
    public function run(bool $includeDerived, bool $keepExistingAttendance, bool $dry = false): array
    {
        $scan = $this->scan($keepExistingAttendance);
        if ($scan['blockers']) {
            throw new \RuntimeException('Cleanup refused: '.collect($scan['blockers'])->map(fn ($ids, $why) => "{$why}: ids ".implode(',', array_slice($ids, 0, 20)))->implode('; '));
        }
        if ($scan['derived'] && ! $includeDerived) {
            throw new \RuntimeException('Cleanup refused: rows made during the demo on dataset records exist ('.collect($scan['derived'])->map(fn ($ids, $t) => "{$t}: ".count($ids))->implode(', ').'). Review them, then rerun with --include-derived.');
        }

        $deleted = [];
        $files = $this->m->files();
        DB::beginTransaction();
        try {
            foreach ($scan['derived'] as $table => $ids) {
                if ($table === 'payment_proofs') {
                    foreach (DB::table('payment_proofs')->whereIn('id', $ids)->get(['file_disk', 'file_path']) as $f) {
                        $files[] = "{$f->file_disk}:{$f->file_path}";
                    }
                }
                foreach (array_chunk($ids, 500) as $chunk) {
                    $deleted["{$table} (derived)"] = ($deleted["{$table} (derived)"] ?? 0) + DB::table($table)->whereIn('id', $chunk)->delete();
                }
            }
            $existingAtt = [];
            if ($keepExistingAttendance) {
                $E = $this->ids('employees') ?: [0];
                $existingAtt = DB::table('attendances')->whereIn('id', $this->ids('attendances') ?: [0])->whereNotIn('employee_id', $E)->pluck('id')->all();
            }
            foreach (self::ORDER as $table) {
                $ids = array_diff($this->ids($table), $table === 'attendances' ? $existingAtt : []);
                if ($table === 'deliveries') {
                    // top-ups point at their parent (set null), so order is free
                    DB::table('deliveries')->whereIn('id', $ids ?: [0])->update(['parent_delivery_id' => null]);
                }
                if ($table === 'employee_memos') {
                    DB::table('employees')->whereIn('id', $this->ids('employees') ?: [0])->update(['investigation_status' => null]);
                }
                $n = 0;
                foreach (array_chunk($ids, 500) as $chunk) {
                    $n += DB::table($table)->whereIn('id', $chunk)->delete();
                }
                if ($n) {
                    $deleted[$table] = $n;
                }
                $this->m->forget($table, $ids);
            }
            if ($dry) {
                DB::rollBack();

                return $deleted + ['(dry run: nothing deleted)' => 0];
            }
            DB::table(Manifest::TABLE)->where('dataset', $this->m->dataset)->where('table_name', 'file')->delete();
            if ($keepExistingAttendance && $existingAtt) {
                // their manifest rows stay so a later cleanup can still find them
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        foreach (array_unique($files) as $ref) {
            [$disk, $path] = explode(':', $ref, 2);
            Storage::disk($disk)->delete($path);
        }
        $deleted['files'] = count(array_unique($files));

        return $deleted;
    }
}
