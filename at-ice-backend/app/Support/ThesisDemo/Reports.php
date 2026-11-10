<?php

namespace App\Support\ThesisDemo;

use App\Models\Employee;
use App\Support\LeaveCredits;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Handoff files: accounts CSV, payroll reconciliation CSV and the checks of
// `thesis-demo verify`. Passwords exist only in the accounts CSV, written at
// the moment the login is made; a rerun keeps the earlier values.
class Reports
{
    public const MONEY = ['basic_pay', 'paid_leave_pay', 'holiday_pay', 'holiday_ot_pay', 'overtime_pay', 'night_diff_pay', 'late_deduction', 'absence_deduction',
        'leave_deduction', 'adjustments_total', 'gross_pay', 'sss_deduction', 'philhealth_deduction', 'pagibig_deduction', 'tax_deduction',
        'taxable_income', 'carry_over_in', 'net_pay', 'carry_over_out'];

    public const COUNTS = ['days_present', 'absent_days', 'late_minutes', 'paid_leave_days', 'unpaid_leave_days', 'unpaid_leave_hours', 'holiday_hours', 'ot_hours', 'night_hours'];

    public function __construct(private Context $c, private string $dir) {}

    private function path(string $name): string
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }

        return rtrim($this->dir, '/\\').DIRECTORY_SEPARATOR.$name;
    }

    private function write(string $name, array $header, array $rows): string
    {
        $file = $this->path($name);
        $h = fopen($file, 'w');
        fputcsv($h, $header);
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);
        @chmod($file, 0600);

        return $file;
    }

    public function accounts(): string
    {
        $c = $this->c;
        $file = $this->path('THESIS-DEMO-ACCOUNTS.csv');
        $old = [];
        if (is_file($file) && ($h = fopen($file, 'r'))) {
            $head = fgetcsv($h);
            while ($r = fgetcsv($h)) {
                $row = array_combine($head, array_pad($r, count($head), ''));
                $old[$row['login_email']] = $row['temporary_password'];
            }
            fclose($h);
        }
        $rows = [];
        foreach (Roster::people() as $code => $p) {
            $e = DB::table('employees')->where('id', $c->emp[$code] ?? 0)->first();
            if (! $e) {
                continue;
            }
            $u = $e->user_id ? DB::table('users')->where('id', $e->user_id)->first() : null;
            $email = $u->email ?? '';
            $pw = $c->credentials[$code][1] ?? ($old[$email] ?? ($u ? '(set at first install, see earlier export)' : ''));
            $status = ! $u ? 'no login (attendance only)' : (! $u->is_active ? 'inactive: login refused (employment ended)' : ($e->date_hired > $c->asOf->toDateString() ? 'active; starts '.$e->date_hired : 'active'));
            $rows[] = [$code, $email ?: '(none)', $u ? $u->role : '-', $u->sub_role ?? '', $e->employee_id, "{$e->first_name} {$e->last_name}", $u ? $pw : '',
                $u ? ($u->must_change_password ? 'yes' : 'no') : '', $status, $p[11], isset($c->credentials[$code]) ? 'generated this run' : 'from an earlier run'];
        }
        foreach (CommerceBuilder::customers() as $k => $def) {
            $cu = DB::table('customers')->where('id', $c->cust[$k] ?? 0)->first();
            if (! $cu || ! $cu->user_id) {
                continue;
            }
            $u = DB::table('users')->where('id', $cu->user_id)->first();
            $pw = $c->credentials[$k][1] ?? ($old[$u->email] ?? '(set at first install, see earlier export)');
            $rows[] = [$k, $u->email, 'customer', '', $cu->customer_code, $cu->name, $pw, $u->must_change_password ? 'yes' : 'no', 'active',
                'Customer portal: deliveries, invoices, payment proofs, prices, notices, support (C-03..C-06, S-01..S-08)', isset($c->credentials[$k]) ? 'generated this run' : 'from an earlier run'];
        }
        foreach ($c->existingEmployeeIds as $id) {
            $e = DB::table('employees')->where('id', $id)->first();
            $u = $e && $e->user_id ? DB::table('users')->where('id', $e->user_id)->first() : null;
            if ($e) {
                $rows[] = ['existing', $u->email ?? '(none)', $u->role ?? '-', $u->sub_role ?? '', $e->employee_id, "{$e->first_name} {$e->last_name}", '(your own password, not changed)', '', 'existing account, unchanged', 'Seeded past attendance for payroll (P-43, K-07..K-15 live)', 'existing'];
            }
        }

        return $this->write('THESIS-DEMO-ACCOUNTS.csv', ['scenario_code', 'login_email', 'role', 'sub_role', 'employee_or_customer_code', 'name', 'temporary_password',
            'must_change_password_at_first_login', 'account_status', 'scenarios', 'credential_source'], $rows);
    }

    // Every fictional employee x every ended period, and every existing
    // employee x their seeded periods: expected (independent) vs stored.
    public function payroll(): array
    {
        $c = $this->c;
        $x = new ExpectedPayroll;
        $pb = new PayrollBuilder($c, (new PeopleBuilder($c))->dates());
        $rows = [];
        $mismatch = [];
        $subjects = [];
        foreach (array_keys(Roster::people()) as $code) {
            $subjects[] = [$code, $c->emp[$code], $c->ended];
        }
        foreach ($c->existingEmployeeIds as $id) {
            $subjects[] = ['existing', $id, $c->existingPeriods];
        }
        foreach ($subjects as [$code, $empId, $periods]) {
            $e = DB::table('employees')->where('id', $empId)->first();
            if (! $e) {
                continue;
            }
            $carry = 0.0;
            foreach ($periods as $p) {
                if ($code !== 'existing' && ! $pb->eligible($code, $p)) {
                    continue;
                }
                // existing employees: only periods that end on or after the hire
                // date (TimesheetController lists no one before that)
                if ($code === 'existing' && substr((string) $e->date_hired, 0, 10) > $p['end']) {
                    continue;
                }
                $actual = DB::table('payrolls')->where('employee_id', $empId)->where('period_start', $p['start'])->first();
                // existing employees and the awaiting period: whatever HR added live
                $adj = ($code === 'existing' || $p === $c->awaiting)
                    ? ($actual ? DB::table('payroll_adjustments')->where('payroll_id', $actual->id)->get()->map(fn ($a) => [$a->label, (float) $a->amount, (bool) $a->taxable])->all() : [])
                    : $pb->adjustments($code, $p);
                if ($code === 'existing') {
                    // as PayrollCalculator does: the shortfall of the latest stored
                    // payroll before this period, none when there is no payroll
                    $carry = (float) DB::table('payrolls')->where('employee_id', $empId)->where('period_start', '<', $p['start'])->orderByDesc('period_start')->value('carry_over_out');
                }
                $exp = $x->compute($e, $p, $adj, $carry);
                $carry = $exp['carry_over_out'];
                $diffs = [];
                if ($actual) {
                    foreach (array_merge(self::MONEY, self::COUNTS) as $f) {
                        if (abs((float) $actual->$f - (float) $exp[$f]) > 0.005) {
                            $diffs[] = "{$f} exp {$exp[$f]} act {$actual->$f}";
                        }
                    }
                }
                // existing employee, period not seeded: its payroll predates the dataset
                $seeded = $code !== 'existing' || in_array("att:existing:{$empId}:", array_map(fn ($k) => substr($k, 0, strrpos($k, ':') + 1),
                    DB::table(Manifest::TABLE)->where('dataset', $c->m->dataset)->where('scenario_key', 'like', "att:existing:{$empId}:%")
                        ->whereBetween(DB::raw('SUBSTRING(scenario_key, -10)'), [$p['start'], $p['end']])->pluck('scenario_key')->all()), true);
                if (! $seeded) {
                    $diffs = [];
                }
                // a paid period of an existing employee gets a seeded payroll (PayrollBuilder::buildExisting)
                $isPaidPeriod = in_array($p, $c->paid, true);
                $state = ! $seeded ? 'NOT SEEDED (payroll existed before the dataset; not compared)'
                    : (! $actual ? ($p === $c->awaiting || ($code === 'existing' && ! $isPaidPeriod) ? 'NOT YET APPROVED (HR approves live)' : 'MISSING') : ($diffs ? 'MISMATCH' : 'MATCH'));
                if ($diffs || $state === 'MISSING') {
                    $mismatch[] = "{$e->employee_id} {$p['start']}: {$state} ".implode('; ', $diffs);
                }
                $row = [$code, $e->employee_id, "{$e->first_name} {$e->last_name}", $e->employment_status, $e->shift, $e->pay_basis,
                    $p['start'], $p['end'], $p['pay_day'], $exp['pay_day_no'], $exp['gov_share'], $exp['scheduled_days'], $exp['missing_time_out'],
                    $exp['daily_rate'], $exp['hourly_rate']];
                foreach (self::COUNTS as $f) {
                    $row[] = $exp[$f];
                }
                foreach (self::MONEY as $f) {
                    $row[] = $exp[$f];
                }
                $row[] = $actual?->status ?? '';
                $row[] = $actual ? $actual->gross_pay : '';
                $row[] = $actual ? $actual->net_pay : '';
                $row[] = $state;
                $row[] = implode('; ', $diffs);
                $row[] = $exp['steps'];
                $rows[] = $row;
            }
        }
        $header = array_merge(['scenario_code', 'employee_code', 'name', 'status', 'shift', 'pay_basis', 'period_start', 'period_end', 'pay_day', 'pay_day_no_in_month',
            'gov_share', 'scheduled_work_days', 'missing_time_out'], ['daily_rate', 'hourly_rate'],
            array_map(fn ($f) => "exp_{$f}", self::COUNTS), array_map(fn ($f) => "exp_{$f}", self::MONEY),
            ['actual_status', 'actual_gross', 'actual_net', 'comparison', 'differences', 'formula_steps']);

        return [$this->write('THESIS-DEMO-PAYROLL.csv', $header, $rows), count($rows), $mismatch];
    }

    // [table, column, description, validator] for every JSON column the installer writes
    public static function jsonShapes(): array
    {
        $list = fn ($v) => is_array($v) && array_is_list($v) && $v !== [];
        $types = array_keys(\App\Models\DeliveryItem::PRODUCT_TYPES);
        $int = fn ($x) => is_int($x) && $x >= 0;
        $change = fn ($v) => $list($v) && collect($v)->every(fn ($i) => is_array($i) && in_array($i['product_type'] ?? null, $types, true)
            && $int($i['before'] ?? null) && $int($i['after'] ?? null) && count($i) === 3);

        return [
            ['delivery_adjustment_requests', 'items', 'a list of {product_type, before, after}', $change],
            ['delivery_adjustments', 'changes', 'a list of {product_type, before, after}', $change],
            ['delivery_contracts', 'items', 'a list of {product_type, quantity}', fn ($v) => $list($v) && collect($v)->every(fn ($i) => is_array($i)
                && in_array($i['product_type'] ?? null, $types, true) && is_int($i['quantity'] ?? null) && $i['quantity'] >= 1)],
            ['delivery_contracts', 'delivery_days', 'a list of mon..sun', fn ($v) => $list($v) && ! array_diff($v, \App\Models\DeliveryContract::DAYS)],
        ];
    }

    // Consistency checks; returns lines (FAIL lines start with "FAIL").
    public function checks(): array
    {
        $c = $this->c;
        $m = $c->m;
        $out = [];
        $ok = function (bool $cond, string $msg) use (&$out) {
            $out[] = ($cond ? 'PASS ' : 'FAIL ').$msg;
        };

        // every manifest row still exists
        foreach (DB::table(Manifest::TABLE)->where('dataset', $m->dataset)->whereNotNull('record_id')->select('table_name')->distinct()->pluck('table_name') as $t) {
            $ids = $m->ids($t);
            $have = 0;
            foreach (array_chunk($ids, 500) as $ch) {
                $have += DB::table($t)->whereIn('id', $ch)->count();
            }
            $ok($have === count($ids), "{$t}: {$have}/".count($ids).' manifest rows present'.($have < count($ids) ? ' (rows removed outside the installer, e.g. retention review)' : ''));
        }

        $att = DB::table('attendances')->whereIn('id', $m->ids('attendances') ?: [0]);
        $ok((clone $att)->where('date', '>=', $c->asOf->toDateString())->count() === 0, 'no seeded attendance on or after the as-of date (live kiosk scans cannot collide)');
        $dupes = (clone $att)->selectRaw('employee_id, date, count(*) n')->groupBy('employee_id', 'date')->havingRaw('count(*) > 1')->count();
        $ok($dupes === 0, 'no duplicate seeded attendance per employee and date');
        $open = (clone $att)->whereNull('time_out')->count();
        $ok($open === 6, "seeded rows without Time Out: {$open} (expected 6: E10 once, E19 five times)");
        $existingOpen = (clone $att)->whereNotIn('employee_id', $m->ids('employees') ?: [0])->whereNull('time_out')->count();
        $ok($existingOpen === 0, 'existing employees: every seeded day has a Time Out (no memo can be drafted for them)');

        // invoices agree with items and deliveries
        $bad = DB::table('receipts as r')->whereIn('r.id', $m->ids('receipts') ?: [0])
            ->whereRaw('ABS(r.total_amount - (SELECT COALESCE(SUM(i.line_total),0) FROM receipt_items i WHERE i.receipt_id = r.id)) > 0.01')->count();
        $ok($bad === 0, 'every invoice total = sum of its lines');
        $bad = DB::table('receipt_items as i')->join('receipts as r', 'r.id', '=', 'i.receipt_id')
            ->join('delivery_items as d', fn ($j) => $j->on('d.delivery_id', '=', 'r.delivery_id')->on('d.product_type', '=', 'i.product_type'))
            ->whereIn('r.id', $m->ids('receipts') ?: [0])
            ->where(fn ($q) => $q->whereColumn('i.quantity', '!=', 'd.quantity_delivered')->orWhereColumn('i.unit_price', '!=', 'd.unit_price'))->count();
        $ok($bad === 0, 'every invoice line = delivered quantity x delivery price');
        $bad = DB::table('deliveries')->whereIn('id', $m->ids('deliveries') ?: [0])->where('status', 'delivered')
            ->whereNotIn('id', DB::table('receipts')->select('delivery_id'))->count();
        $ok($bad === 0, 'every delivered delivery has a proof and an invoice');
        $bad = DB::table('deliveries')->whereIn('id', $m->ids('deliveries') ?: [0])->where('status', 'delivered')
            ->whereRaw('(SELECT COUNT(*) FROM delivery_arrivals a WHERE a.delivery_id = deliveries.id) < 2')->count();
        $ok($bad === 0, 'every delivered delivery has Driver and Salesman arrivals');
        $bad = DB::table('deliveries')->whereIn('id', $m->ids('deliveries') ?: [0])->where('scheduled_date', '>=', $c->asOf->toDateString())->whereNotNull('driver_id')->count();
        $ok($bad === 0, 'no dataset delivery on or after the as-of date has a fictional driver (dispatcher cannot move them to real drivers)');

        // JSON columns have the shape the app reads (AdjustmentRequests.jsx,
        // AdjustmentRequestPanel.jsx, SameDayChanges.jsx, ContractsTab.jsx,
        // DeliveryAdjust::applyRequest, DeliveryDispatch::generate)
        foreach (self::jsonShapes() as [$table, $column, $label, $valid]) {
            $mine = $m->ids($table);
            $bad = [];
            $badReal = [];
            foreach (DB::table($table)->get(['id', $column]) as $r) {
                if (! $valid(json_decode((string) $r->$column, true))) {
                    in_array((int) $r->id, $mine, true) ? $bad[] = $r->id : $badReal[] = $r->id;
                }
            }
            $ok(! $bad, "{$table}.{$column} is {$label}".($bad ? ' (bad ids '.implode(',', $bad).')' : ''));
            if ($badReal) {
                $out[] = "WARN {$table}.{$column}: rows outside the dataset with another shape (ids ".implode(',', $badReal).'); the app may fail on them';
            }
        }

        // stock agrees with confirmed movements
        foreach (DB::table('products')->whereIn('id', $m->ids('products') ?: [0])->get() as $p) {
            $sum = (int) DB::table('stock_movements')->where('product_id', $p->id)->where('status', 'confirmed')
                ->selectRaw("COALESCE(SUM(CASE WHEN type='stock_in' THEN quantity WHEN type='stock_out' THEN -quantity ELSE stock_after - stock_before END),0) s")->value('s');
            $ok($sum === (int) $p->current_stock, "{$p->product_code} stock {$p->current_stock} = confirmed movements {$sum}");
        }

        // an adjustment's quantity is the counted stock (StockMovementController@confirm)
        $bad = DB::table('stock_movements')->whereIn('id', $m->ids('stock_movements') ?: [0])->where('type', 'adjustment')
            ->where('status', 'confirmed')->whereColumn('quantity', '!=', 'stock_after')->pluck('id')->all();
        $ok(! $bad, 'every stock adjustment quantity = the counted stock after it'.($bad ? ' (bad ids '.implode(',', $bad).')' : ''));

        // a released stock-out is recorded the way StockOutRequestController@release records it
        $bad = [];
        foreach (DB::table('stock_out_requests')->whereIn('id', $m->ids('stock_out_requests') ?: [0])->where('status', 'released')->get() as $r) {
            $mv = DB::table('stock_movements')->where('id', $r->stock_movement_id)->first();
            if (! $mv || $mv->reference_no !== 'SOR-'.str_pad((string) $r->id, 4, '0', STR_PAD_LEFT) || (int) $mv->entered_by !== (int) $r->requested_by
                || $mv->source !== 'maintenance' || $mv->created_at !== $r->released_at || (int) $mv->quantity !== (int) $r->quantity) {
                $bad[] = $r->id;
            }
        }
        $ok(! $bad, 'every released stock-out has its SOR stock out, entered by the requester at the release time'.($bad ? ' (bad request ids '.implode(',', $bad).')' : ''));

        // a hired applicant has an employee record (first and last name)
        $bad = [];
        foreach (DB::table('applicants')->whereIn('id', $m->ids('applicants') ?: [0])->where('status', 'hired')->get(['id', 'name']) as $a) {
            $parts = explode(' ', $a->name);
            if (! DB::table('employees')->where('first_name', $parts[0])->where('last_name', end($parts))->exists()) {
                $bad[] = $a->name;
            }
        }
        $ok(! $bad, 'every hired applicant has an employee record'.($bad ? ' (missing: '.implode(', ', $bad).')' : ''));

        // leave ranges hold only work days, except L-16 (weekend counted: defect D3)
        $bad = [];
        $leaveKeys = $m->keys('leave_requests');
        foreach (DB::table('leave_requests')->whereIn('id', array_keys($leaveKeys) ?: [0])->get(['id', 'start_date', 'end_date']) as $l) {
            if ($leaveKeys[$l->id] === 'leave:L-16') {
                continue;
            }
            for ($d = $c->date($l->start_date); $d->toDateString() <= substr((string) $l->end_date, 0, 10); $d = $d->addDay()) {
                if (! $c->isWorkDay($d->toDateString()) || $c->isHoliday($d->toDateString())) {
                    $bad[] = $leaveKeys[$l->id];
                    break;
                }
            }
        }
        $ok(! $bad, 'no leave range holds a rest day or holiday, except L-16 on purpose'.($bad ? ' (bad: '.implode(', ', $bad).')' : ''));

        // interviews on work days that are not holidays
        $bad = DB::table('applicants')->whereIn('id', $m->ids('applicants') ?: [0])->whereNotNull('interview_date')->get(['name', 'interview_date'])
            ->filter(function ($a) use ($c) {
                $d = CarbonImmutable::parse($a->interview_date, config('app.timezone'))->setTimezone(Context::TZ)->toDateString();

                return ! $c->isWorkDay($d) || $c->isHoliday($d);
            })->pluck('name')->all();
        $ok(! $bad, 'every interview is on a work day that is not a holiday'.($bad ? ' (bad: '.implode(', ', $bad).')' : ''));

        // a reviewed change request was reviewed after it was filed
        $bad = DB::table('delivery_adjustment_requests')->whereIn('id', $m->ids('delivery_adjustment_requests') ?: [0])
            ->whereNotNull('reviewed_at')->whereColumn('reviewed_at', '<=', 'created_at')->pluck('id')->all();
        $ok(! $bad, 'every reviewed change request was reviewed after it was filed'.($bad ? ' (bad ids '.implode(',', $bad).')' : ''));

        // existing employees: each paid period with seeded attendance has a payroll
        $bad = [];
        foreach ($c->existingEmployeeIds as $id) {
            $hired = substr((string) DB::table('employees')->where('id', $id)->value('date_hired'), 0, 10);
            foreach ($c->paid as $p) {
                if ($hired <= $p['end'] && PayrollBuilder::hasSeededAttendance($c, $id, $p)
                    && ! DB::table('payrolls')->where('employee_id', $id)->where('period_start', $p['start'])->exists()) {
                    $bad[] = DB::table('employees')->where('id', $id)->value('employee_id')." {$p['start']}";
                }
            }
        }
        $ok(! $bad, 'existing employees: every paid period with seeded attendance has a payroll (the awaiting one stays for HR)'.($bad ? ' (missing: '.implode(', ', $bad).')' : ''));

        // leave credits: independent count vs the app
        foreach (['E12', 'E13'] as $code) {
            // independent: 0.5 per 14-day period that ended, counted from the
            // period holding max(regularized, Jan 1), capped at 15
            $e = Employee::find($c->emp[$code]);
            $from = max(substr((string) $e->regularized_at, 0, 10), $c->asOf->startOfYear()->toDateString());
            $anchor = CarbonImmutable::parse((string) Settings::get('payroll.anchor'));
            $start = CarbonImmutable::parse($from)->subDays((($anchor->diffInDays(CarbonImmutable::parse($from), false) % 14) + 14) % 14);
            $n = 0;
            for ($end = $start->addDays(13); $end->toDateString() <= $c->asOf->toDateString(); $end = $end->addDays(14)) {
                $n++;
            }
            $earned = min(15, $n * 0.5);
            $used = (float) DB::table('leave_requests')->where('employee_id', $e->id)->where('status', 'approved')->whereYear('start_date', $c->asOf->year)->sum('paid_days');
            $app = LeaveCredits::for($e, $c->asOf->toDateString());
            $ok(abs($app['earned'] - $earned) < 0.001 && abs($app['used'] - $used) < 0.01,
                "{$code} leave credits: independent earned {$earned}, used {$used}; app earned {$app['earned']}, used {$app['used']}, available {$app['available']}");
        }

        // memos
        $e19 = DB::table('employee_memos')->where('employee_id', $c->emp['E19'])->whereNotIn('id', $m->ids('employee_memos') ?: [0])->get();
        $out[] = $e19->count()
            ? 'INFO MemoEngine drafted '.$e19->count().' memo(s) for E19: '.$e19->map(fn ($x) => "{$x->memo_no} {$x->level} offense {$x->offense_no} {$x->status}")->implode(', ')
            : 'INFO MemoEngine has not drafted the E19 memo yet (runs every 10 minutes with the scheduler, or on the live-feed poll; locally run: php artisan schedule:run)';

        return $out;
    }
}
