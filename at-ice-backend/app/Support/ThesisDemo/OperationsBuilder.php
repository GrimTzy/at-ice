<?php

namespace App\Support\ThesisDemo;

use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Supplies (never ice products), suppliers, stock movements with consistent
// before/after counts, a low-stock alert, stock-out requests in every state
// on a project, projects, pickup logs, expenses, applicants, an employee
// document and performance reviews. Only new rows; real stock is untouched.
class OperationsBuilder
{
    public function __construct(private Context $c, private SampleFiles $files) {}

    public function build(): void
    {
        $this->suppliers();
        $projects = $this->projects();
        $this->stock($projects);
        $this->pickups();
        $this->expenses();
        $this->applicants();
        $this->documents();
        $this->performance();
    }

    private function suppliers(): void
    {
        $c = $this->c;
        $rows = [
            'S1' => ['Bulacan Packaging Supply', 'Mila Garcia', 'Plastic bags, sack liners and twine.'],
            'S2' => ['Norte Industrial Hardware', 'Ben Agustin', 'Compressor parts, fittings and tools.'],
        ];
        foreach ($rows as $k => [$name, $contact, $notes]) {
            if ($c->m->has("supplier:{$k}")) {
                continue;
            }
            $n = (int) DB::table('suppliers')->max('id') + 1;
            do {
                $code = 'SPL-'.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
            } while (DB::table('suppliers')->where('supplier_code', $code)->exists());
            $at = $c->stamp($c->ended[0]['start'], '10:00:00');
            $c->m->insert("supplier:{$k}", 'suppliers', [
                'supplier_code' => $code, 'name' => $name, 'contact_person' => $contact, 'contact_number' => null, 'email' => null,
                'address' => 'San Jose del Monte, Bulacan', 'city' => 'San Jose del Monte', 'province' => 'Bulacan',
                'status' => 'active', 'notes' => $notes, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    private function projects(): array
    {
        $c = $this->c;
        $rows = [
            'PJ1' => ['Ice crusher motor rewinding', 'Motor of crusher #2 overheats; rewind and replace bearings.', 'Plant, crusher room', 'done', 'high', -60, -40, 15000],
            'PJ2' => ['Cold storage door gasket replacement', 'Gaskets of the cold room door are torn; replace all four sides.', 'Plant, cold room A', 'working', 'medium', -10, 7, 6000],
            'PJ3' => ['Brine tank leak repair', 'Small leak at the brine tank weld seam.', 'Plant, tank area', 'stuck', 'critical', -14, -2, 20000],
            'PJ4' => ['Loading bay lighting', 'Add two LED floodlights at the loading bay for night loading.', 'Plant, loading bay', 'planned', 'low', null, null, null],
        ];
        $out = [];
        foreach ($rows as $k => [$name, $desc, $loc, $status, $prio, $s, $due, $budget]) {
            $at = $c->stamp($c->asOf->addDays(($s ?? -3) - 5)->toDateString(), '10:00:00');
            $out[$k] = $c->m->insert("project:{$k}", 'projects', [
                'name' => $name, 'description' => $desc, 'location' => $loc, 'latitude' => 14.8031, 'longitude' => 121.0515,
                'status' => $status, 'priority' => $prio, 'owner' => $status === 'planned' ? null : 'Arturo Galang',
                'start_date' => $s === null ? null : $c->asOf->addDays($s)->toDateString(),
                'due_date' => $due === null ? null : $c->asOf->addDays($due)->toDateString(),
                'budget' => $budget, 'created_by' => $c->user['E23'], 'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        return $out;
    }

    private function stock(array $projects): void
    {
        $c = $this->c;
        $products = [
            'P1' => ['Sack liner, big (50kg)', 'packaging', 'piece', 4.50, 400, 2000],
            'P2' => ['Plastic bag 5kg, pack of 100', 'packaging', 'pack', 180.00, 20, 120],
            'P3' => ['Polypropylene twine roll', 'packaging', 'roll', 95.00, 10, 60],
            'P4' => ['Compressor V-belt B-52', 'spare_parts', 'piece', 650.00, 2, 10],
            'P5' => ['Cold room door gasket (per meter)', 'spare_parts', 'meter', 320.00, 6, 40],
        ];
        // movements per product: [type, qty, daysAgo, status, reason/source]
        $moves = [
            'P1' => [['stock_in', 1500, 80, 'confirmed', 'Bulacan Packaging Supply DR'], ['stock_out', 700, 50, 'confirmed', 'Used for big sack deliveries'], ['stock_out', 600, 20, 'confirmed', 'Used for big sack deliveries'], ['stock_out', 150, 2, 'pending', 'Daily use (to confirm)']],
            'P2' => [['stock_in', 60, 75, 'confirmed', 'Bulacan Packaging Supply DR'], ['stock_out', 30, 40, 'confirmed', 'Bags for dealers'], ['stock_out', 18, 9, 'confirmed', 'Bags for dealers'], ['adjustment', -2, 5, 'confirmed', 'Count after inventory: 2 packs torn']],
            'P3' => [['stock_in', 30, 70, 'confirmed', 'Bulacan Packaging Supply DR'], ['stock_out', 8, 30, 'confirmed', 'Tying sacks'], ['stock_in', 10, 4, 'cancelled', 'Duplicate entry']],
            'P4' => [['stock_in', 6, 65, 'confirmed', 'Norte Industrial Hardware DR']],
            'P5' => [['stock_in', 20, 30, 'confirmed', 'Norte Industrial Hardware DR']],
        ];
        $base = (int) DB::table('products')->max('id') + 1;
        $ids = [];
        foreach ($products as $k => [$name, $cat, $unit, $price, $min, $max]) {
            if ($id = $c->m->id("product:{$k}")) {
                $ids[$k] = $id;

                continue;
            }
            $n = $base++;
            do {
                $code = 'SUP-'.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
            } while (DB::table('products')->where('product_code', $code)->exists());
            $base = $n;
            $at = $c->stamp($c->asOf->subDays(90)->toDateString(), '09:00:00');
            $ids[$k] = $c->m->insert("product:{$k}", 'products', [
                'product_code' => $code, 'name' => $name, 'category' => $cat, 'unit' => $unit, 'unit_price' => $price,
                'current_stock' => 0, 'minimum_stock' => $min, 'maximum_stock' => $max, 'description' => null, 'is_active' => true,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }

        // stock-out requests by Maintenance (E23) on the projects; the released
        // one adds its own confirmed stock out (Admin release)
        $sor = [
            ['released', 'P4', 2, 'PJ1', 'Replace worn belts on crusher #2.', 55],
            ['approved', 'P5', 12, 'PJ2', 'Gaskets for the cold room door (4 sides).', 3],
            ['rejected', 'P4', 4, 'PJ3', 'Spare belts for the brine pump.', 12, 'Brine pump uses a different belt size; request the B-48 instead.'],
            ['pending', 'P5', 4, 'PJ3', 'Extra gasket for the tank hatch.', 1],
        ];

        $tm = fn (int $daysAgo, string $t) => $c->stamp($c->asOf->subDays($daysAgo)->toDateString(), $t);
        foreach ($ids as $k => $pid) {
            $stock = 0;
            $list = $moves[$k];
            foreach ($sor as $s) {
                if ($s[0] === 'released' && $s[1] === $k) {
                    // released by Admin at 15:00, two days after the request
                    $list[] = ['stock_out', $s[2], $s[5] - 2, 'confirmed', $s[4], 'sor'];
                }
            }
            usort($list, fn ($a, $b) => $b[2] <=> $a[2]);
            foreach ($list as $i => $mv) {
                [$type, $qty, $ago, $status, $why] = $mv;
                $release = ($mv[5] ?? null) === 'sor';
                $delta = $type === 'stock_in' ? $qty : ($type === 'stock_out' ? -$qty : $qty);
                $after = $status === 'confirmed' ? $stock + $delta : $stock;
                // An adjustment's quantity is the counted stock: confirming it
                // sets the stock to that number (StockMovementController@confirm).
                $quantity = $type === 'adjustment' ? $stock + $delta : abs($qty);
                // A release is written the way StockOutRequestController@release
                // writes it: entered by the Maintenance requester, source
                // "maintenance", reason = purpose, at the release time. Its
                // SOR-#### reference is set below, once the request has an id.
                $mid = $c->m->insert("move:{$k}:{$i}", 'stock_movements', [
                    'product_id' => $pid, 'type' => $type, 'quantity' => $quantity, 'stock_before' => $stock, 'stock_after' => $status === 'confirmed' ? $after : $stock + $delta,
                    'reference_no' => $type === 'stock_in' ? 'DR-'.(40000 + Context::pick("{$k}{$i}", 100, 999)) : null,
                    'source' => $type === 'stock_in' ? $why : ($release ? 'maintenance' : null), 'reason' => $type === 'stock_in' ? null : $why,
                    'status' => $status, 'entered_by' => $release ? $c->user['E23'] : $c->user['E01'],
                    'confirmed_by' => $status === 'confirmed' ? $c->adminUserId : null,
                    'confirmed_at' => $status === 'confirmed' ? $tm($ago, '15:00:00') : null,
                    'created_at' => $tm($ago, $release ? '15:00:00' : '09:30:00'), 'updated_at' => $tm($ago, '15:00:00'),
                ]);
                if (($mv[5] ?? null) === 'sor') {
                    $releasedMove = $mid;
                }
                $stock = $after;
            }
            DB::table('products')->where('id', $pid)->update(['current_stock' => $stock]);
            if ($stock < $products[$k][4]) {
                $c->m->insert("alert:{$k}", 'inventory_alerts', [
                    'product_id' => $pid, 'alert_type' => $stock <= 0 ? 'out_of_stock' : 'low_stock',
                    'message' => "{$products[$k][0]} is low: {$stock} left (minimum {$products[$k][4]}).",
                    'is_resolved' => false, 'created_at' => $tm(5, '15:05:00'), 'updated_at' => $tm(5, '15:05:00'),
                ]);
            }
            foreach ($sor as $j => $s) {
                if ($s[1] !== $k) {
                    continue;
                }
                [$st, , $qty, $pj, $purpose, $ago] = $s;
                $sorId = $c->m->insert("sor:{$j}", 'stock_out_requests', [
                    'requested_by' => $c->user['E23'], 'product_id' => $pid, 'project_id' => $projects[$pj],
                    'item_name' => $products[$k][0], 'quantity' => $qty, 'purpose' => $purpose, 'status' => $st,
                    'approved_by' => in_array($st, ['approved', 'released'], true) ? $c->adminUserId : null,
                    'approved_at' => in_array($st, ['approved', 'released'], true) ? $tm($ago - 1, '10:00:00') : null,
                    'rejection_reason' => $s[6] ?? null,
                    'released_by' => $st === 'released' ? $c->adminUserId : null,
                    'released_at' => $st === 'released' ? $tm($ago - 2, '15:00:00') : null,
                    'stock_movement_id' => $st === 'released' ? ($releasedMove ?? null) : null,
                    'created_at' => $tm($ago, '08:30:00'), 'updated_at' => $tm(max(0, $ago - 2), '15:00:00'),
                ]);
                if ($st === 'released' && isset($releasedMove)) {
                    DB::table('stock_movements')->where('id', $releasedMove)
                        ->update(['reference_no' => 'SOR-'.str_pad((string) $sorId, 4, '0', STR_PAD_LEFT)]);
                }
            }
            unset($releasedMove);
        }
    }

    private function pickups(): void
    {
        $c = $this->c;
        foreach ([[60, 'Hacienda Fresh Seafoods', 'big_sack', 12], [45, 'Barrio Fiesta Catering', 'small_sack', 20], [31, 'Hacienda Fresh Seafoods', 'big_sack', 15],
            [17, 'Sto. Cristo Fish Port Vendors', 'bags', 80], [8, 'Barrio Fiesta Catering', 'small_sack', 25], [3, 'Hacienda Fresh Seafoods', 'big_sack', 10]] as $i => [$ago, $co, $type, $qty]) {
            $at = $c->asOf->subDays($ago);
            while ($at->isWeekend()) {
                $at = $at->subDay();
            }
            $checker = collect(['E01', 'E14', 'E12'])->first(fn ($x) => $c->m->has("att:{$x}:".$at->toDateString())) ?? 'E01';
            $c->m->insert("pickup:{$i}", 'pickup_logs', [
                'company_name' => $co, 'product_type' => $type, 'quantity' => $qty, 'recorded_by' => $c->user[$checker],
                'notes' => 'Picked up at the plant by the buyer\'s own truck.', 'pickup_date' => $at->toDateString().' 14:20:00',
                'created_at' => $c->stamp($at->toDateString(), '14:25:00'), 'updated_at' => $c->stamp($at->toDateString(), '14:25:00'),
            ]);
        }
    }

    private function expenses(): void
    {
        $c = $this->c;
        $start = $c->date($c->ended[0]['start'])->startOfMonth();
        for ($m = $start; $m->lessThan($c->asOf); $m = $m->addMonth()) {
            foreach ([['electricity', 5, 68000, 82000, 'Meralco bill (plant)'], ['water', 7, 9000, 12500, 'Water district bill (plant)'],
                ['fuel', 10, 14000, 19000, 'Diesel, delivery trucks (1st half)'], ['fuel', 25, 14000, 19000, 'Diesel, delivery trucks (2nd half)'],
                ['others', 18, 2500, 6000, 'Truck tire repair and supplies']] as $i => [$cat, $day, $lo, $hi, $note]) {
                $d = $m->setDay($day);
                if ($d->greaterThanOrEqualTo($c->asOf)) {
                    continue;
                }
                $c->m->insert('expense:'.$m->format('Y-m').":{$i}", 'expenses', [
                    'category' => $cat, 'amount' => Context::pick($m->format('Ym').$i, $lo, $hi), 'expense_date' => $d->toDateString(), 'notes' => $note,
                    'created_at' => $c->stamp($d->toDateString(), '16:00:00'), 'updated_at' => $c->stamp($d->toDateString(), '16:00:00'),
                ]);
            }
        }
    }

    private function applicants(): void
    {
        $c = $this->c;
        $a = $c->asOf;
        // Interviews fall on work days that are not holidays: future ones move
        // forward to the next such day, past ones back to the previous one.
        $workday = function (CarbonImmutable $d, int $step) use ($c): CarbonImmutable {
            while (! $c->isWorkDay($d->toDateString()) || $c->isHoliday($d->toDateString())) {
                $d = $d->addDays($step);
            }

            return $d;
        };
        // A5 is the hire of E20 Joanna Castillo: interviewed, hired, then HR
        // made her employee record (start date after the as-of date).
        $e20 = DB::table('employees')->where('id', $c->emp['E20'] ?? 0)->first(['employee_id', 'date_hired']);
        $a5Note = 'Hired. Employee record '.($e20->employee_id ?? 'E20').', starts '.($e20 ? substr((string) $e20->date_hired, 0, 10) : 'after the as-of date')
            .'; must change the one-time password at first login.';
        $rows = [
            ['A1', 'Jomar P. Bernardo', 'Driver', 'pending', $workday($a->addDays(3), 1)->setTime(10, 0), 'hr', null, 'Has a professional license with restriction code B, B1, B2 (as stated by the applicant).'],
            ['A2', 'Shiela M. Romero', 'Checker', 'pending', $workday($a->addDays(4), 1)->setTime(14, 0), 'qr', null, 'Applied through the QR form at the gate.'],
            ['A3', 'Ariel T. Domingo', 'Salesman', 'interviewed', $workday($a->subDays(5), -1)->setTime(9, 0), 'hr', 'E12', 'Good communication; for second interview with Admin. Referred by Angelica Rivera.'],
            ['A4', 'Rhea L. Santiago', 'Bagger', 'rejected', $workday($a->subDays(15), -1)->setTime(13, 30), 'hr', null, 'Did not report for the scheduled interview.'],
            ['A5', 'Joanna R. Castillo', 'Checker', 'hired', $workday($a->subDays(12), -1)->setTime(10, 30), 'hr', null, $a5Note],
            ['A6', 'Melvin A. Cruz', 'Guard', 'pending', $workday($a->addDays(5), 1)->setTime(9, 30), 'qr', null, 'Security guard license holder (as stated).'],
        ];
        foreach ($rows as [$k, $name, $pos, $status, $when, $source, $ref, $notes]) {
            $created = $c->stamp($when->subDays(6)->toDateString(), '11:00:00');
            $local = strtolower(preg_replace('/[^a-z]/i', '', explode(' ', $name)[0])).'.'.strtolower(preg_replace('/[^a-z]/i', '', explode(' ', $name)[2] ?? 'x'));
            $c->m->insert("applicant:{$k}", 'applicants', [
                'name' => $name, 'email' => "{$local}.applicant@{$c->domain}", 'phone' => null,
                'barangay' => 'Muzon', 'city' => 'San Jose del Monte', 'province' => 'Bulacan', 'region' => 'Region III (Central Luzon)',
                'position_applied' => $pos, 'interview_date' => $when->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'), 'interview_minutes' => 30,
                'status' => $status, 'source' => $source, 'referred_by_employee_id' => $ref ? $c->emp[$ref] : null, 'notes' => $notes,
                'consented_at' => $source === 'qr' ? $created : null,
                // last status change: the interview day, or the next work day for a hire
                'created_at' => $created, 'updated_at' => match ($status) {
                    'pending' => $created,
                    'hired' => $c->stamp($workday($when->addDay(), 1)->toDateString(), '09:00:00'),
                    default => $c->stamp($when->toDateString(), '17:00:00'),
                },
            ]);
        }
    }

    private function documents(): void
    {
        $c = $this->c;
        $e = Employee::find($c->emp['E12']);
        $path = $this->files->pdf('doc:E12:nbi', 'local', 'employee-documents', 'NBI Clearance (sample)', [
            'Name: '.$e->first_name.' '.$e->last_name, 'This is a fictional sample, not an NBI document.',
        ]);
        $c->m->insert('empdoc:E12:nbi', 'employee_documents', [
            'employee_id' => $e->id, 'type' => 'nbi', 'path' => $path, 'original_name' => 'nbi-clearance-sample.pdf',
            'mime' => 'application/pdf', 'size' => (\Illuminate\Support\Facades\Storage::disk('local')->exists($path) ? \Illuminate\Support\Facades\Storage::disk('local')->size($path) : 0),
            'uploaded_by' => $c->hrUserId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function performance(): void
    {
        $c = $this->c;
        $p = $c->paidFromEnd(1);
        foreach ([['E05', 4.5, 'published', 'Consistent on-time deliveries; zero customer complaints this period.'], ['E07', 4.0, 'draft', 'Good proof photos; remind to confirm deliveries right away.']] as [$code, $rating, $status, $remarks]) {
            $at = $c->stamp($c->date($p['end'])->addDays(3)->toDateString(), '15:00:00');
            $c->m->insert("perf:{$code}", 'performance_reviews', [
                'employee_id' => $c->emp[$code], 'period_start' => $p['start'], 'period_end' => $p['end'], 'final_rating' => $rating,
                'goal_target' => null, 'remarks' => $remarks, 'status' => $status, 'updated_by' => $c->adminUserId,
                'published_by' => $status === 'published' ? $c->adminUserId : null, 'published_at' => $status === 'published' ? $at : null,
                'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }
}
