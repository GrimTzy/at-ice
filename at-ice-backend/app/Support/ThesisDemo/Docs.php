<?php

namespace App\Support\ThesisDemo;

use App\Models\Employee;
use App\Support\LeaveCredits;
use App\Support\PayPeriod;
use App\Support\PayrollCalculator;
use App\Support\ThirteenthMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Writes THESIS-DEMO-WALKTHROUGH.md and THESIS-DEMO-COVERAGE.md with the
// record numbers of the database it runs on (EMP, DEL, invoice, ticket, memo
// and SUP numbers differ per database and per as-of date). Read only: it
// never writes to the database. Run by `thesis-demo export` and `verify`.
class Docs
{
    private array $e = []; // code => employees row

    public function __construct(private Context $c, private string $dir) {}

    public function write(array $target): array
    {
        foreach ($this->c->emp as $code => $id) {
            $this->e[$code] = DB::table('employees')->where('id', $id)->first();
        }
        if (count($this->e) < count(Roster::people())) {
            return [];
        }
        $files = [];
        foreach (['THESIS-DEMO-WALKTHROUGH.md' => $this->walkthrough($target), 'THESIS-DEMO-COVERAGE.md' => $this->coverage($target)] as $name => $text) {
            $file = rtrim($this->dir, '/\\').DIRECTORY_SEPARATOR.$name;
            file_put_contents($file, $text);
            $files[] = $file;
        }

        return $files;
    }

    // ---- lookups -------------------------------------------------------

    private function code(string $k): string
    {
        return $this->e[$k]->employee_id;
    }

    private function name(string $k): string
    {
        return $this->e[$k]->first_name.' '.$this->e[$k]->last_name;
    }

    private function who(string $k): string
    {
        return "**{$this->name($k)}** ({$this->code($k)})";
    }

    private function login(string $k): string
    {
        $u = $this->e[$k]->user_id ? DB::table('users')->where('id', $this->e[$k]->user_id)->value('email') : null;

        return $u ? "`{$u}`" : 'no login';
    }

    private function cust(string $k): ?object
    {
        return DB::table('customers')->where('id', $this->c->cust[$k] ?? 0)->first();
    }

    private function ids(string $t): array
    {
        return $this->c->m->ids($t) ?: [0];
    }

    private function d(?string $ymd): string
    {
        return $ymd ? CarbonImmutable::parse(substr($ymd, 0, 10))->format('M j, Y') : '-';
    }

    private function dShort(?string $ymd): string
    {
        return $ymd ? CarbonImmutable::parse(substr($ymd, 0, 10))->format('M j') : '-';
    }

    private function period(array $p): string
    {
        return $this->dShort($p['start']).' to '.$this->d($p['end']);
    }

    private function peso($v): string
    {
        return 'P'.number_format((float) $v, 2);
    }

    private function deliveries()
    {
        return DB::table('deliveries as d')->leftJoin('customers as cu', 'cu.id', '=', 'd.customer_id')
            ->whereIn('d.id', $this->ids('deliveries'))->select('d.*', 'cu.name as customer');
    }

    private function list(iterable $rows, callable $fmt, int $max = 6): string
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = $fmt($r);
        }
        if (! $out) {
            return '(none)';
        }

        return implode(', ', array_slice($out, 0, $max)).(count($out) > $max ? ' and '.(count($out) - $max).' more' : '');
    }

    private function sheet(string $k, array $p): array
    {
        return PayrollCalculator::timesheet(Employee::find($this->c->emp[$k]), CarbonImmutable::parse($p['start']), CarbonImmutable::parse($p['end']));
    }

    private function payroll(string $k, array $p): ?object
    {
        return DB::table('payrolls')->where('employee_id', $this->c->emp[$k])->where('period_start', $p['start'])->first();
    }

    // ---- walkthrough ---------------------------------------------------

    private function walkthrough(array $target): string
    {
        $c = $this->c;
        $aw = $c->awaiting;
        $p1 = $c->paidFromEnd(1);
        $p2 = $c->paidFromEnd(2);
        $awPayDay = PayPeriod::payDayNumber($aw['pay_day']);
        $L = [];
        $L[] = '# Thesis Demo Walkthrough';
        $L[] = '';
        $L[] = "Generated from database `{$target['database']}` on ".now(Context::TZ)->format('M j, Y g:i A').' (Manila). Dataset as-of date: **'.$this->d($c->asOf->toDateString()).'**.';
        $L[] = 'Every record number below (EMP, DEL, invoice, ticket, memo, SUP) was read from that database. After any `thesis-demo refresh`, run `php artisan thesis-demo export` and use the new file.';
        $L[] = '';
        $L[] = '**Accounts.** "HR" and "Admin" are your own accounts. Fictional logins and their passwords are in `THESIS-DEMO-ACCOUNTS.csv` (made by the install on this database). Scenario IDs in brackets ([P-17], [M-04]) are listed in `THESIS-DEMO-COVERAGE.md`.';
        $L[] = '';
        $L[] = '**Tell the panel at the start:** "These people and stores are fictional, made for the thesis demo. Past attendance is seeded history, not camera scans. Only what we do live today comes from the real kiosk and phones."';
        $L[] = '';
        $L[] = '## Before the demo';
        $L[] = '';
        $L[] = '- Every group member who will use a Checker, Salesman, Maintenance or Driver page must **Time In at the kiosk first**. Those pages stay locked until then.';
        $faces = DB::table('employees as e')->whereIn('e.id', $c->existingEmployeeIds ?: [0])
            ->whereNotExists(fn ($q) => $q->from('face_data as f')->whereColumn('f.employee_id', 'e.id'))->pluck('employee_id')->all();
        $L[] = '- Face enrollment missing for: '.($faces ? implode(', ', $faces).'. HR enrolls them (HR > Face Enrollment) before the demo.' : 'none.');
        $L[] = '- Do not change the kiosk PIN on live: it locks every kiosk.';
        $L[] = '- For the live delivery (section B, Driver and Salesman): move the map pin of **Bahay Kubo Eatery** to the venue first.';
        $L[] = '';
        $L[] = '## A. Core sequence (15 to 20 minutes)';
        $L[] = '';
        $L[] = '### 1. Attendance Station, live [K-03, K-07, K-09]';
        $L[] = '- **Account:** none; HR types the kiosk PIN.';
        $L[] = '- **Do:** open `/attendance-station` on the laptop, enter the PIN, and one group member does **Time In**. Try Time In again: the kiosk refuses ("Time In already recorded").';
        $L[] = '- **Say:** "The server clock is used, not the browser. Times cannot be edited; HR can only add notes."';
        $L[] = '';
        $L[] = '### 2. Timesheet to payroll (main feature) [P-43, P-32]';
        $L[] = '- **Account:** HR > **Timesheets** (`/hr/timesheets`), period **'.$this->period($aw).'** (not approved yet).';
        $s8 = $this->sheet('E08', $aw);
        $s10 = $this->sheet('E10', $aw);
        $s13 = $this->sheet('E13', $aw);
        $s14 = $this->sheet('E14', $aw);
        $L[] = "- **Show:** {$this->who('E08')} {$s8['late_minutes']} late minutes; {$this->who('E10')} {$s10['absent_days']} absent day(s); {$this->who('E13')} {$s13['paid_leave_days']} paid leave day(s), {$s13['unpaid_leave_days']} unpaid, {$s13['unpaid_leave_hours']} unpaid emergency-leave hours; {$this->who('E14')} {$s14['ot_pending_minutes']} min overtime waiting for approval.";
        $L[] = '- **Do:** select everyone and **Approve** (tick the OT of '.$this->name('E14').' if you want it paid).';
        $L[] = '- **Say:** "Approval is allowed only after the period ends." Try to approve the current period **'.$this->period($c->current).'**: it is refused.';
        $e11 = PayrollCalculator::compute(Employee::find($c->emp['E11']), $aw, []);
        $gov = (float) $e11['sss_deduction'] + (float) $e11['philhealth_deduction'] + (float) $e11['pagibig_deduction'];
        $L[] = '- **Then:** HR > **Payroll** (`/hr/payroll`), same period. Open the payslip of '.$this->who('E11').' (monthly P40,000):';
        $L[] = '  - basic = 40,000 x 12 / 26 = **'.$this->peso($e11['basic_pay']).'**';
        $L[] = "  - pay day {$this->d($aw['pay_day'])} is pay day #{$awPayDay} of its month: ".($gov > 0 ? 'government deductions '.$this->peso($gov).' (half of the monthly share)' : '**no SSS, PhilHealth or Pag-IBIG** (they are split over the 1st and 2nd pay day only)');
        $L[] = '  - tax '.$this->peso($e11['tax_deduction']).', net **'.$this->peso($e11['net_pay']).'**';
        $L[] = '- **Add adjustment:** Rice allowance P500 to '.$this->who('E01').', non-taxable. Then **Mark paid**. Say: "This records the payment; there is no bank transfer."';
        $L[] = '';
        $L[] = '### 3. A paid period, to explain the formula [P-01..P-38]';
        $L[] = 'HR > **Payroll**, period **'.$this->period($p2).'** (pay day '.$this->d($p2['pay_day']).', pay day #'.PayPeriod::payDayNumber($p2['pay_day']).' of the month):';
        $L[] = '';
        $L[] = '| Person | What it shows | Stored result |';
        $L[] = '|---|---|---|';
        $r01 = $this->payroll('E01', $p2);
        $L[] = "| {$this->who('E01')} | perfect attendance + P500 non-taxable rice allowance | gross {$this->peso($r01->gross_pay ?? 0)}, SSS {$this->peso($r01->sss_deduction ?? 0)}, PhilHealth {$this->peso($r01->philhealth_deduction ?? 0)}, Pag-IBIG {$this->peso($r01->pagibig_deduction ?? 0)}, net **{$this->peso($r01->net_pay ?? 0)}** |";
        $r09 = DB::table('payrolls')->where('employee_id', $c->emp['E09'])->whereIn('id', $this->ids('payrolls'))->orderByDesc('late_minutes')->first();
        if ($r09) {
            $L[] = "| {$this->who('E09')} | late by hours and a half day (defect D1: deducted twice) | ".$this->period(['start' => $r09->period_start, 'end' => $r09->period_end]).": {$r09->days_present} days, {$r09->late_minutes} late min = {$this->peso($r09->late_deduction)} |";
        }
        $r18 = DB::table('payrolls')->where('employee_id', $c->emp['E18'])->whereIn('id', $this->ids('payrolls'))->where('carry_over_out', '>', 0)->first();
        if ($r18) {
            $next = DB::table('payrolls')->where('employee_id', $c->emp['E18'])->where('period_start', '>', $r18->period_start)->orderBy('period_start')->first();
            $L[] = "| {$this->who('E18')} | almost the whole period absent | ".$this->period(['start' => $r18->period_start, 'end' => $r18->period_end]).": gross {$this->peso($r18->gross_pay)}, net **P0.00**, carried over **{$this->peso($r18->carry_over_out)}**".($next ? ', taken in the next period ('.$this->dShort($next->period_start).')' : '').' [P-37, P-38] |';
        }
        $first15 = DB::table('payrolls')->where('employee_id', $c->emp['E15'])->orderBy('period_start')->first();
        if ($first15) {
            $L[] = "| {$this->who('E15')} | hired mid-period on {$this->d($this->e['E15']->date_hired)} | first payroll: {$first15->days_present} days [P-28] |";
        }
        $L[] = '';
        $L[] = '### 4. Leave [L-01, L-16]';
        $cr = LeaveCredits::for(Employee::find($c->emp['E12']), $c->asOf->toDateString());
        $l01 = DB::table('leave_requests')->where('id', $c->m->id('leave:L-01') ?? 0)->first();
        $l16 = DB::table('leave_requests')->where('id', $c->m->id('leave:L-16') ?? 0)->first();
        $L[] = "- **Account:** {$this->who('E12')}, login {$this->login('E12')} > `/my-leave`. Credits: earned {$cr['earned']}, used {$cr['used']}, **available {$cr['available']}** (0.5 per period once regular, max 15, reset every Jan 1).";
        $L[] = '  - File a VL for next week: refused (14 days notice). File an SL dated 3 days ago: refused (one day after return).';
        if ($l01) {
            $L[] = '- **Account:** HR > **Leave Requests**: approve her pending VL **'.$this->dShort($l01->start_date).' to '.$this->d($l01->end_date).'**; it is paid from credits.';
        }
        if ($l16) {
            $L[] = '- **Point out:** her VL **'.$this->dShort($l16->start_date).' (Fri) to '.$this->d($l16->end_date)." (Mon)** counts **{$l16->total_days} days**, weekend included (defect D3).";
        }
        $L[] = '';
        $L[] = '### 5. Memo ladder and automation [M-01..M-05]';
        $memos = DB::table('employee_memos')->where('employee_id', $c->emp['E19'])->orderBy('incident_date')->get();
        $L[] = "- **Account:** HR > **Memos** (`/hr/memos`). {$this->who('E19')}, Driver:";
        foreach ($memos as $m) {
            $L[] = "  - {$m->memo_no}: ".str_replace('_', ' ', $m->level).", offense #{$m->offense_no}, incident {$this->d($m->incident_date)}, status **".str_replace('_', ' ', $m->status).'**'.($m->source === 'auto' ? ' (drafted by the system)' : '');
        }
        if (! $memos->contains('source', 'auto')) {
            $L[] = '  - The system will draft a **Notice to Explain, offense #4** from his latest missed Time Out within about 10 minutes of the install (MemoEngine). Run `export` again to list its number.';
        }
        $L[] = '- **Say:** "His 2025 memo is older than 12 months, so it does not count. The next one is #4, not #5. Nothing is automatic beyond the draft: HR sends it."';
        $L[] = '- **Do:** **Send** the draft: status "awaiting physical" and "Pending investigation".';
        $tard = DB::table('employee_memos')->where('employee_id', $c->emp['E08'])->where('status', 'sent')->value('memo_no');
        $L[] = "- **Account:** {$this->who('E08')}, login {$this->login('E08')} > memos > **Acknowledge** {$tard} (it lists her late days).";
        $L[] = '';
        $L[] = '### 6. Delivery to invoice to payment [D-01, D-14, D-15, C-05]';
        $short = $this->deliveries()->join('delivery_proofs as p', 'p.delivery_id', '=', 'd.id')->whereNotNull('p.notes')->orderBy('d.scheduled_date')->get(['d.delivery_no', 'cu.name as customer', 'd.scheduled_date']);
        $re = $this->deliveries()->whereNotNull('d.reassigned_from_driver_id')->orderBy('d.scheduled_date')->get();
        $top = $this->deliveries()->where('d.is_top_up', true)->first();
        $L[] = '- **Account:** Admin > **Deliveries** (`/admin/deliveries`), history:';
        $L[] = '  - short deliveries (invoice charges only what was delivered): '.$this->list($short, fn ($r) => "{$r->delivery_no} ({$r->customer}, {$this->dShort($r->scheduled_date)})");
        $L[] = '  - reassigned from '.$this->name('E06').' with a "may be delayed" notice: '.$this->list($re, fn ($r) => "{$r->delivery_no} ({$this->dShort($r->scheduled_date)})");
        if ($top) {
            $L[] = "  - urgent top-up: {$top->delivery_no} ({$top->customer}, {$this->dShort($top->scheduled_date)})";
        }
        $k2 = $this->cust('K2');
        $r = DB::table('receipts')->whereIn('id', $this->ids('receipts'))->where('customer_id', $k2->id ?? 0);
        $pend = DB::table('payment_proofs as pp')->join('receipts as r', 'r.id', '=', 'pp.receipt_id')->whereIn('pp.id', $this->ids('payment_proofs'))->get(['pp.status', 'r.receipt_no']);
        $L[] = "- **Account:** customer **{$k2->name}**, login `".DB::table('users')->where('id', $k2->user_id ?? 0)->value('email').'` > **Receipts**:';
        $L[] = '  - overdue now (unpaid 14 days or more): '.$this->list((clone $r)->where('payment_status', 'overdue')->orderBy('created_at')->get(), fn ($x) => $x->receipt_no).'. This list grows every day; it is decided when the page opens.';
        $L[] = '  - payment proofs: '.$this->list($pend, fn ($x) => "{$x->status} on {$x->receipt_no}");
        $L[] = '- **Account:** Admin > **Receipts** > payment proofs > **Approve** the pending one: the invoice becomes paid.';
        $L[] = '';
        $L[] = '## B. Extended walkthrough by role';
        $L[] = '';
        $L[] = '### HR';
        $L[] = '1. **Employees** (`/hr/employees`):';
        $L[] = "   - perfect attendance in four combinations: {$this->who('E01')} regular day, {$this->who('E02')} regular night, {$this->who('E03')} probationary day, {$this->who('E04')} Guard, probationary night, no login.";
        $L[] = "   - {$this->who('E17')}: inactive, last day {$this->d($this->e['E17']->deactivated_at)}; final payroll in the period that holds that day.";
        $hiredIsE20 = DB::table('applicants')->whereIn('id', $this->ids('applicants'))->where('status', 'hired')
            ->where('name', 'like', $this->e['E20']->first_name.'%'.$this->e['E20']->last_name)->exists();
        $L[] = "   - {$this->who('E20')}: starts {$this->d($this->e['E20']->date_hired)}; must change the password at first login.".($hiredIsE20 ? ' She is the hired applicant in Interviews.' : '');
        $L[] = "   - {$this->who('E21')}: inactive since {$this->d($this->e['E21']->deactivated_at)} (retention review).";
        $L[] = '2. **Attendance** (`/hr/attendance`):';
        $L[] = "   - {$this->who('E22')}: a Time In after midnight is dated the next day (defect D4), and a Sunday night shift that crosses a cut-off.";
        $L[] = "   - {$this->who('E10')}: one day with no Time Out still counts as a full day (D2); an early out at 15:00 is still a full day (no undertime rule).";
        $L[] = "   - {$this->who('E14')}: worked every holiday in the window; overtime approved, not approved, and pending.";
        $L[] = "   - {$this->who('E02')}: overnight overtime, approved.";
        $L[] = '   - Export CSV.';
        $L[] = '3. **Leave Requests:** '.$this->who('E13').' (not enough credits: part paid; one rejected with a reason; unpaid emergency-leave hours). '.$this->who('E12').' (SL with a sample medical certificate, SL marked unpaid by HR, half day). '.$this->who('E03').' (probationary: filing refused).';
        $m09 = DB::table('employee_memos')->where('employee_id', $c->emp['E09'])->value('memo_no');
        $m10 = DB::table('employee_memos')->where('employee_id', $c->emp['E10'])->where('status', 'draft')->value('memo_no');
        $L[] = "4. **Memos:** {$this->who('E09')} {$m09} (zero tolerance, always NTE) > **Close investigation**. {$this->who('E10')} draft {$m10} > **Cancel** with a reason. {$this->who('E07')} has a cancelled memo (wrong employee), which no longer counts.";
        $t = fn (string $k) => ThirteenthMonth::for(Employee::find($c->emp[$k]), $c->asOf->year);
        $L[] = '5. **Payroll > 13th month:** '.$this->name('E01').' '.$this->peso($t('E01')['amount'] ?? 0).', '.$this->name('E11').' '.$this->peso($t('E11')['amount'] ?? 0).', '.$this->name('E16').' (hired '.$this->d($this->e['E16']->date_hired).') '.$this->peso($t('E16')['amount'] ?? 0).'.';
        $L[] = '6. **Interviews** (`/hr/interviews`): '.$this->list(DB::table('applicants')->whereIn('id', $this->ids('applicants'))->orderBy('interview_date')->get(), fn ($a) => "{$a->name} ({$a->position_applied}, {$a->status}, ".$this->dShort($a->interview_date).')', 10).'.';
        $L[] = '7. **Face Enrollment / Kiosk PIN:** show only. Do not change the PIN on live.';
        $L[] = '';
        $L[] = '### Admin';
        $contracts = DB::table('delivery_contracts')->whereIn('id', $this->ids('delivery_contracts'))->orderBy('id')->get();
        $pending = $this->deliveries()->where('d.status', 'pending')->orderBy('d.scheduled_date')->get();
        $L[] = '1. **Deliveries:**';
        $L[] = '   - Contracts: '.$this->list($contracts, fn ($k) => "{$k->contract_no} {$k->name} ({$k->status}, ".$this->dShort($k->start_date).' to '.$this->dShort($k->end_date).')').'. **Approve** the pending one live: its deliveries are created **without a driver**; assign them by hand.';
        $L[] = '   - Upcoming, no driver yet: '.$this->list($pending, fn ($x) => "{$x->delivery_no} ({$x->customer}, {$this->dShort($x->scheduled_date)})", 8).'. Assign them only to a group-member Driver who has timed in.';
        $adjPending = DB::table('delivery_adjustment_requests as r')->join('deliveries as d', 'd.id', '=', 'r.delivery_id')->whereIn('r.id', $this->ids('delivery_adjustment_requests'))->get(['r.status', 'd.delivery_no']);
        $L[] = '   - Store change requests: '.$this->list($adjPending, fn ($x) => "{$x->status} on {$x->delivery_no}").'. Apply or decline the pending one live.';
        $l18 = DB::table('leave_requests')->where('id', $c->m->id('leave:L-18') ?? 0)->first();
        $L[] = "   - Board: {$this->who('E06')} is on approved leave ".($l18 ? $this->dShort($l18->start_date).' to '.$this->d($l18->end_date) : '').'; fictional drivers show "Not timed in".';
        $L[] = '2. **Customers / Customer Prices:** dealer (Tindahan ni Aling Rosa, Mang Berto Ice Dealer, Bahay Kubo Eatery, Lola Nena\'s Halo-Halo), supermarket chain (Nuevo Mart x2), convenience store (Kanto Express Graceville). Aling Rosa pays the dealer **reference price**; the others have their own prices. Lola Nena\'s is the newest customer (created '.$this->d($this->cust('K6')->created_at ?? null).') with a full story: prices, deliveries, invoices, payment, repeat orders, ticket [C-06].';
        $tickets = DB::table('support_messages as s')->join('customers as cu', 'cu.id', '=', 's.customer_id')->whereIn('s.id', $this->ids('support_messages'))->orderByDesc('s.created_at')->get(['s.ticket_no', 's.topic', 's.status', 's.priority', 'cu.name']);
        $L[] = '3. **Support Inbox** (`/admin/support-inbox`): '.$this->list($tickets, fn ($x) => "{$x->ticket_no} {$x->name}, ".str_replace('_', ' ', $x->topic).", {$x->priority}, **".str_replace('_', ' ', $x->status).'**', 10).'. Reopen the closed one live.';
        $prods = DB::table('products')->whereIn('id', $this->ids('products'))->orderBy('product_code')->get();
        $L[] = '4. **Inventory** (`/checker/inventory` as Admin): '.$this->list($prods, fn ($p) => "{$p->product_code} {$p->name} {$p->current_stock}/{$p->minimum_stock}".($p->current_stock <= $p->minimum_stock ? ' (low)' : ''), 10).'.';
        $sors = DB::table('stock_out_requests')->whereIn('id', $this->ids('stock_out_requests'))->orderBy('id')->get();
        $L[] = '   **Stock-Out Approvals** (`/admin/stock-out-approvals`), requests by '.$this->who('E23').': '.$this->list($sors, fn ($s) => "#{$s->id} {$s->item_name} x{$s->quantity} ({$s->status})").'. **Release** the approved one (stock goes down, a SOR stock out is recorded); approve or reject the pending one.';
        $L[] = '5. **Projects** (`/admin/projects`): done, working, stuck, planned.';
        $L[] = "6. **Performance** (`/admin/performance`): {$this->who('E05')} published 4.5; {$this->who('E07')} draft > **Publish**.";
        $L[] = "7. **Settings > Retention:** {$this->who('E21')} > **Keep** or **Anonymize**. Act on him only.";
        $L[] = '8. **Dashboard / BI:** sales chart and forecast (6 months), overdue invoices, expenses. **Say:** "This includes both real records and the thesis dataset."';
        $L[] = '';
        $L[] = '### Customer portal';
        $L[] = 'Use **Kanto Express Graceville** (`'.DB::table('users')->where('id', $this->cust('K4')->user_id ?? 0)->value('email').'`) or **Lola Nena\'s Halo-Halo** (`'.DB::table('users')->where('id', $this->cust('K6')->user_id ?? 0)->value('email').'`): deliveries and "may be delayed" notices, delivery proofs (sample photos), prices, support. Try a second ticket on the same topic: refused (one active per topic). An Emergency Ice Shortage ticket is never blocked.';
        $L[] = '';
        $L[] = '### Driver and Salesman, live (real phones and GPS) [D-12, D-13]';
        $L[] = '1. Admin: move the map pin of **Bahay Kubo Eatery** to the venue.';
        $L[] = '2. Admin: create a delivery for today for Bahay Kubo and assign a group-member Driver who has timed in.';
        $L[] = '3. Driver: **Start** (in transit), then **Arrived**.';
        $L[] = '4. Salesman: **Arrived**, then **Confirm** with quantity and photo. The invoice is made automatically.';
        $L[] = 'Both must have timed in at the kiosk and be within 150 m of the pin.';
        $L[] = '';
        $L[] = '### Your group\'s accounts';
        $L[] = '';
        $L[] = '| EMP | Name | Login | Seeded past attendance | Seeded paid payroll |';
        $L[] = '|---|---|---|---|---|';
        foreach ($c->existingEmployeeIds as $id) {
            $e = DB::table('employees')->where('id', $id)->first();
            $days = DB::table('attendances')->whereIn('id', $this->ids('attendances'))->where('employee_id', $id)->count();
            $pays = DB::table('payrolls')->whereIn('id', $this->ids('payrolls'))->where('employee_id', $id)->orderBy('period_start')->get();
            $L[] = "| {$e->employee_id} | {$e->first_name} {$e->last_name} | `".(DB::table('users')->where('id', $e->user_id ?? 0)->value('email') ?? '-')."` | {$days} days | ".$this->list($pays, fn ($p) => $this->period(['start' => $p->period_start, 'end' => $p->period_end]).' net '.$this->peso($p->net_pay)).' |';
        }
        $L[] = '';
        $L[] = 'Their real kiosk test scans are kept and counted (for example a very short Time In with many late minutes). Say so if the panel asks. The period **'.$this->period($aw).'** is left for HR to approve live, for them too.';
        $L[] = '';
        $L[] = '## C. Likely panel questions';
        $L[] = '';
        $L[] = '1. **"How do you know the payroll is right?"** A separate calculator (ExpectedPayroll) recomputes every payroll from the raw attendance, leave, holiday and contribution tables without using the system\'s payroll code. `thesis-demo verify` compares both, to the centavo, and writes every step to THESIS-DEMO-PAYROLL.csv.';
        $L[] = '2. **"Why no SSS or PhilHealth on some payslips?"** Pay is every 14 days, so some months have 3 pay days. The setting splits the monthly share over the 1st and 2nd pay day; the 3rd has none. The monthly total is still complete.';
        $L[] = '3. **"What if someone forgets to Time Out?"** Two hours after the shift, the system drafts a memo, and the level rises with the number of offenses in 12 months: verbal, first written, final written, Notice to Explain. HR still sends it; nothing is automatic beyond the draft. We also found that payroll still counts that day as a full day (D2) and listed it for fixing.';
        $L[] = '4. **"What if a Driver is absent?"** 60 minutes after shift start with no Time In, or on approved leave, the system moves their pending deliveries to an available driver (or a queue) and tells the store it may be late. Example: the reassigned stops of '.$this->name('E06').'.';
        $L[] = '5. **"Is this data real?"** No. The people, stores, numbers and sample files are fictional; sample files say "SAMPLE ONLY". Seeded attendance carries a note on every row. Everything added is listed in a manifest, so it can be removed without touching real records.';
        $L[] = '';

        return implode("\n", $L);
    }

    // ---- coverage (scenario matrix with this database's numbers) -------

    private function coverage(array $target): string
    {
        $c = $this->c;
        $L = [];
        $L[] = '# Thesis Demo Coverage and Scenario Matrix';
        $L[] = '';
        $L[] = "Database `{$target['database']}`, dataset as-of **".$this->d($c->asOf->toDateString()).'**, generated '.now(Context::TZ)->format('M j, Y g:i A').' (Manila). Record numbers are read from this database.';
        $L[] = '';
        $L[] = '## Pay periods';
        $L[] = '';
        $L[] = '| Period | Pay day | State |';
        $L[] = '|---|---|---|';
        foreach (array_slice($c->paid, -4) as $p) {
            $L[] = '| '.$this->period($p).' | '.$this->d($p['pay_day']).' (#'.PayPeriod::payDayNumber($p['pay_day']).') | paid history |';
        }
        $L[] = '| '.$this->period($c->awaiting).' | '.$this->d($c->awaiting['pay_day']).' (#'.PayPeriod::payDayNumber($c->awaiting['pay_day']).') | **awaiting HR approval (live)** |';
        $L[] = '| '.$this->period($c->current).' | '.$this->d($c->current['pay_day']).' | current (attendance up to the day before the as-of date) |';
        $L[] = '';
        $L[] = '## Fictional roster';
        $L[] = '';
        $L[] = '| Code | EMP | Name | Position | Status | Login | Used for |';
        $L[] = '|---|---|---|---|---|---|---|';
        foreach (Roster::people() as $k => $p) {
            $e = $this->e[$k];
            $state = ! $e->is_active ? 'inactive since '.$this->d($e->deactivated_at) : ($e->date_hired > $c->asOf->toDateString() ? 'starts '.$this->d($e->date_hired) : $e->employment_status);
            $L[] = "| {$k} | {$e->employee_id} | {$this->name($k)} | {$e->position} | {$state} | {$this->login($k)} | {$p[11]} |";
        }
        $L[] = '';
        $L[] = '## Customers';
        $L[] = '';
        $L[] = '| Key | Code | Name | Type | Portal login | Deliveries | Invoices (paid / unpaid / overdue) |';
        $L[] = '|---|---|---|---|---|---|---|';
        foreach (array_keys(CommerceBuilder::customers()) as $k) {
            $cu = $this->cust($k);
            if (! $cu) {
                continue;
            }
            $rs = DB::table('receipts')->where('customer_id', $cu->id)->selectRaw("SUM(payment_status='paid') p, SUM(payment_status='unpaid') u, SUM(payment_status='overdue') o")->first();
            $L[] = "| {$k} | {$cu->customer_code} | {$cu->name} | ".str_replace('_', ' ', $cu->customer_type).' | '.($cu->user_id ? '`'.DB::table('users')->where('id', $cu->user_id)->value('email').'`' : 'none').' | '.DB::table('deliveries')->where('customer_id', $cu->id)->count()." | {$rs->p} / {$rs->u} / {$rs->o} |";
        }
        $L[] = '';
        $L[] = '## Scenario matrix (records in this database)';
        $L[] = '';
        $L[] = '| ID | Scenario | Records | How to check |';
        $L[] = '|---|---|---|---|';
        $del = fn ($q) => $this->list($q->orderBy('d.scheduled_date')->get(), fn ($x) => "{$x->delivery_no} ({$x->customer}, {$this->dShort($x->scheduled_date)})", 5);
        $memo = fn (string $k) => $this->list(DB::table('employee_memos')->where('employee_id', $c->emp[$k])->orderBy('incident_date')->get(), fn ($m) => "{$m->memo_no} ".str_replace('_', ' ', $m->level).' '.str_replace('_', ' ', $m->status));
        $leave = function (string $key) {
            $l = DB::table('leave_requests')->where('id', $this->c->m->id("leave:{$key}") ?? 0)->first();

            return $l ? $this->dShort($l->start_date).($l->end_date !== $l->start_date ? ' to '.$this->dShort($l->end_date) : '')." {$l->leave_type} {$l->status} (paid {$l->paid_days}, unpaid {$l->unpaid_days})" : '-';
        };
        $rows = [
            ['A-02', 'First-login password change', $this->who('E20').', '.$this->login('E20'), 'Log in: asked to change the password'],
            ['A-03', 'Ended employee cannot log in', $this->who('E17').', '.$this->login('E17'), 'Log in: "This account has been deactivated"'],
            ['T-02', 'Late by minutes', $this->who('E08'), 'HR > Attendance'],
            ['T-04', 'Half day, late (D1)', $this->who('E09').', '.$this->who('E18'), 'HR > Attendance, Payroll'],
            ['T-06', 'Missing Time Out (D2)', $this->who('E10').', '.$this->who('E19'), 'HR > Attendance'],
            ['T-07', 'OT approved / not approved / pending', $this->who('E14'), 'HR > Timesheets'],
            ['T-09', 'Night Time In after midnight (D4)', $this->who('E22'), 'HR > Attendance'],
            ['L-01', 'Pending VL for HR', $this->name('E12').': '.$leave('L-01'), 'HR > Leave Requests'],
            ['L-03', 'SL paid with a sample medical certificate', $this->name('E12').': '.$leave('L-03'), 'Open the attachment'],
            ['L-04', 'SL marked unpaid by HR', $this->name('E12').': '.$leave('L-04'), ''],
            ['L-05', 'Not enough credits, part paid', $this->name('E13').': '.$leave('L-05'), ''],
            ['L-07', 'Rejected with a reason', $this->name('E13').': '.$leave('L-07'), ''],
            ['L-08', 'Pending VL the employee cancels live', $this->name('E12').': '.$leave('L-08'), 'Employee > My Leave > Cancel'],
            ['L-15', 'Half-day leave', $this->name('E12').': '.$leave('L-15'), ''],
            ['L-16', 'Leave over a weekend (D3)', $this->name('E12').': '.$leave('L-16'), ''],
            ['L-18', 'Driver on leave, shown off on the board', $this->name('E06').': '.$leave('L-18'), 'Admin > Deliveries > Board'],
            ['M-01..M-05', 'Memo ladder to NTE', $this->name('E19').': '.$memo('E19'), 'HR > Memos'],
            ['M-07', 'Tardiness memo to acknowledge', $this->name('E08').': '.$memo('E08'), 'Employee > My Memos'],
            ['M-08', 'Zero tolerance NTE, investigation', $this->name('E09').': '.$memo('E09'), 'HR > Memos > Close investigation'],
            ['M-11', 'Draft to cancel', $this->name('E10').': '.$memo('E10'), 'HR > Memos > Cancel'],
            ['D-01', 'Delivered: loading, crew, two arrivals, proof, invoice', DB::table('deliveries')->whereIn('id', $this->ids('deliveries'))->where('status', 'delivered')->count().' deliveries', 'Admin > Deliveries'],
            ['D-03', 'Upcoming, no driver yet', $del($this->deliveries()->where('d.status', 'pending')), 'Assign a timed-in group Driver'],
            ['D-05', 'Reassigned + delay notice', $del($this->deliveries()->whereNotNull('d.reassigned_from_driver_id')), 'Customer > notices'],
            ['D-06', 'Cancelled (skip day / emergency)', $this->list($this->deliveries()->where('d.status', 'cancelled')->orderBy('d.scheduled_date')->get(), fn ($x) => "{$x->delivery_no} ({$x->customer}): {$x->cancel_reason}"), ''],
            ['D-07..D-09', 'Contracts pending / approved / cancelled', $this->list(DB::table('delivery_contracts')->whereIn('id', $this->ids('delivery_contracts'))->orderBy('id')->get(), fn ($k) => "{$k->contract_no} {$k->status}"), 'Admin > CRM > Customers > Contracts button'],
            ['D-14', 'Short delivery', $del($this->deliveries()->join('delivery_proofs as p', 'p.delivery_id', '=', 'd.id')->whereNotNull('p.notes')), 'Invoice = delivered quantity'],
            ['D-15', 'Urgent top-up', $del($this->deliveries()->where('d.is_top_up', true)), ''],
            ['D-16', 'Change requests', $this->list(DB::table('delivery_adjustment_requests as r')->join('deliveries as d', 'd.id', '=', 'r.delivery_id')->whereIn('r.id', $this->ids('delivery_adjustment_requests'))->get(['r.status', 'd.delivery_no']), fn ($x) => "{$x->status} {$x->delivery_no}"), 'Admin applies or declines the pending one'],
            ['C-05', 'Payment proofs', $this->list(DB::table('payment_proofs as pp')->join('receipts as r', 'r.id', '=', 'pp.receipt_id')->whereIn('pp.id', $this->ids('payment_proofs'))->get(['pp.status', 'r.receipt_no']), fn ($x) => "{$x->status} {$x->receipt_no}"), 'Admin approves the pending one'],
            ['S-01..S-08', 'Support tickets', $this->list(DB::table('support_messages')->whereIn('id', $this->ids('support_messages'))->orderBy('created_at')->get(), fn ($x) => "{$x->ticket_no} ".str_replace('_', ' ', $x->topic).' '.str_replace('_', ' ', $x->status), 10), 'Admin > Support Inbox'],
            ['I-01..I-04', 'Supplies, low stock, stock-out requests', $this->list(DB::table('products')->whereIn('id', $this->ids('products'))->orderBy('product_code')->get(), fn ($p) => "{$p->product_code} {$p->current_stock}/{$p->minimum_stock}", 10).'; requests '.$this->list(DB::table('stock_out_requests')->whereIn('id', $this->ids('stock_out_requests'))->orderBy('id')->get(), fn ($s) => "#{$s->id} {$s->status}"), 'Admin > Stock-Out Approvals'],
            ['H-01', 'Applicants in every state; the hired one is '.$this->name('E20'), $this->list(DB::table('applicants')->whereIn('id', $this->ids('applicants'))->orderBy('id')->get(), fn ($a) => "{$a->name} {$a->status}", 10), 'HR > Interviews'],
            ['H-04', 'Appraisal published / draft', $this->name('E05').', '.$this->name('E07'), 'Admin > Performance'],
            ['H-05', 'Retention review', $this->who('E21'), 'Admin > Settings > Retention (act on him only)'],
        ];
        foreach ($rows as [$id, $what, $rec, $how]) {
            $L[] = "| {$id} | {$what} | {$rec} | {$how} |";
        }
        $L[] = '';
        $L[] = '## Not covered, and why';
        $L[] = '';
        $L[] = 'Not in the app (nothing seeded): night differential, undertime, corrected attendance times, loans and other deductions, partial payments, credit memos, a "cancelled" leave status, maternity or paternity leave in the form, memo replies or appeals, a reassignment reason or history, a rest-day premium on ordinary days.';
        $L[] = '';
        $L[] = 'Possible in the app but not seeded: a regular holiday on a rest day, a double holiday, a special working day (the installer never adds holidays); other negative-net and government-deduction strategies (global settings); SSS and PhilHealth floors; an inactive person with an enrolled face.';
        $L[] = '';

        return implode("\n", $L);
    }
}
