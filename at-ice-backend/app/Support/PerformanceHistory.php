<?php

namespace App\Support;

use App\Models\DeliveryProof;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\IceLoading;
use App\Models\PerformanceReview;
use App\Models\Project;
use App\Models\StockMovement;
use App\Models\StockOutRequest;
use Carbon\Carbon;
use Illuminate\Support\Arr;

// Read-only performance history of one employee (Admin > HRIS > Performance >
// employee name, a page of its own). One date range and one granularity
// (month or quarter, the "buckets") drive every section:
//   rating trend   published reviews only, grouped by the bucket that holds
//                  the review's period_end (the cutoff); a bucket's value is
//                  the arithmetic mean of its cutoff ratings
//   attendance     the real Attendance records, same rules as the Performance
//                  page (PerformanceScore::attendanceSeries)
//   goals          the role's work done against its goal, taken from the
//                  published reviews
//   records        the live work records behind those numbers (deliveries,
//                  confirmations, loadings, movements, requests, projects)
//   feedback       the remarks of published reviews
// A published review keeps a snapshot of the numbers it was rated from (see
// PerformanceController::update). Reviews published before snapshots existed
// are recalculated from the records that exist now and flagged as such.
class PerformanceHistory
{
    public const DEFAULT_QUARTERS = 8;

    public const MAX_YEARS = 5;

    public const RECORD_LIMIT = 200;

    private const UNIT = [
        'driver' => 'deliveries completed',
        'salesman' => 'delivery confirmations',
        'checker' => 'ice loadings and stock movements',
        'maintenance' => 'stock-out requests and projects created',
    ];

    private const SOURCE = [
        'driver' => 'Deliveries marked delivered to this driver (deliveries.driver_id).',
        'salesman' => 'Delivery proofs this salesman confirmed (delivery_proofs.salesman_id).',
        'checker' => 'Ice loadings checked and stock movements entered by this checker.',
        'maintenance' => 'Stock-out requests this employee made plus projects this employee created on the board.',
    ];

    /** Default range: the last 8 quarters, or this year so far when by month. */
    public static function defaultRange(string $by = 'quarter'): array
    {
        $today = Carbon::parse(DriverAvailability::today());
        $from = $by === 'month'
            ? $today->copy()->startOfYear()
            : $today->copy()->firstOfQuarter()->subMonthsNoOverflow(3 * (self::DEFAULT_QUARTERS - 1));

        return ['from' => $from->toDateString(), 'to' => $today->toDateString()];
    }

    public static function build(Employee $e, string $from, string $to, string $by = 'quarter'): array
    {
        $e->loadMissing('user:id,role,sub_role');
        $role = PerformanceScore::roleOf($e);
        $buckets = self::buckets($from, $to, $by);

        $reviews = PerformanceReview::with('publisher:id,name,role,sub_role')
            ->where('employee_id', $e->id)->where('status', 'published')
            ->whereDate('period_end', '>=', $from)->whereDate('period_end', '<=', $to)
            ->orderBy('period_start')->get();
        $entries = $reviews->map(fn (PerformanceReview $r) => self::entry($r, $e, $by))->values();

        $latest = PerformanceReview::with('publisher:id,name,role,sub_role')
            ->where('employee_id', $e->id)->where('status', 'published')
            ->orderByDesc('period_end')->first();

        // Trend: mean of the cutoff ratings of each bucket
        $trend = [];
        foreach ($buckets as $b) {
            $in = $entries->where('bucket', $b['key'])->whereNotNull('rating')->values();
            $trend[] = $b + [
                'average' => $in->isEmpty() ? null : round($in->avg('rating'), 2),
                'count' => $in->count(),
                'ratings' => $in->map(fn ($x) => Arr::only($x, ['period_start', 'period_end', 'rating', 'source', 'manual']))->all(),
            ];
        }

        // Attendance: real records per bucket, clipped to the chosen range
        $ranges = array_map(fn ($b) => [max($b['start'], $from), min($b['end'], $to)], $buckets);
        $stats = PerformanceScore::attendanceSeries($e, $ranges);
        $attendance = [];
        foreach ($buckets as $i => $b) {
            $st = $stats[$i];
            $attendance[] = $b + [
                'covers' => ['from' => $ranges[$i][0], 'to' => min($ranges[$i][1], DriverAvailability::today())],
                'work_days' => $st['work_days'],
                'present_days' => $st['present_days'],
                'absent_days' => max(0, $st['work_days'] - $st['present_days']),
                'on_time_days' => $st['on_time_days'],
                'attendance_rate' => $st['work_days'] > 0 ? round(min(1, $st['present_days'] / $st['work_days']) * 100, 1) : null,
                'punctuality_rate' => $st['present_days'] > 0 ? round($st['on_time_days'] / $st['present_days'] * 100, 1) : null,
            ];
        }

        // Goals: published cutoffs of the bucket, each capped at its own goal
        $goals = [];
        foreach ($buckets as $b) {
            $in = $role ? $entries->where('bucket', $b['key'])->filter(fn ($x) => ($x['goal_target'] ?? 0) > 0)->values() : collect();
            $target = (int) $in->sum('goal_target');
            $counted = (int) $in->sum(fn ($x) => min((int) $x['goal_done'], (int) $x['goal_target'])); // work above a cutoff's own goal does not cover another cutoff
            $goals[] = $b + [
                'cutoffs' => $in->count(),
                'target' => $in->isEmpty() ? null : $target,
                'done' => $in->isEmpty() ? null : (int) $in->sum('goal_done'),
                'counted' => $in->isEmpty() ? null : $counted,
                'completion' => $in->isEmpty() ? null : round($counted / $target * 100, 1),
                'recalculated' => $in->contains('source', 'recalculated'),
            ];
        }

        // Feedback: the remarks of published reviews, newest first
        $feedback = $entries->filter(fn ($x) => trim((string) $x['remarks']) !== '')->reverse()->values()
            ->map(fn ($x) => Arr::only($x, ['id', 'period_start', 'period_end', 'remarks', 'published_at', 'reviewer', 'rating', 'rating_label']))->all();

        return [
            'employee' => [
                'id' => $e->id,
                'code' => $e->employee_id,
                'name' => $e->full_name,
                'department' => $e->department,
                'position' => $e->position,
                'date_hired' => $e->date_hired?->toDateString(),
                'shift' => $e->shift,
                'role' => $role,
                'has_photo' => EmployeeDocument::where('employee_id', $e->id)->where('type', 'photo')->exists(),
            ],
            'latest' => $latest ? self::entry($latest, $e, $by) : null,
            'range' => ['from' => $from, 'to' => $to, 'today' => DriverAvailability::today()],
            'by' => $by,
            'trend' => $trend,
            'attendance' => $attendance,
            'goals' => [
                'applicable' => $role !== null,
                'unit' => $role ? self::UNIT[$role] : null,
                'source' => $role ? self::SOURCE[$role] : null,
                'quarters' => $goals, // buckets: months or quarters
            ],
            'records' => self::records($e, $role, $from, $to, $by),
            'feedback' => $feedback,
            'counts' => [
                'published' => $entries->count(),
                'snapshot' => $entries->where('source', 'snapshot')->count(),
                'recalculated' => $entries->where('source', 'recalculated')->count(),
            ],
        ];
    }

    // One published review as the history shows it: the snapshot taken when it
    // was published, or (older reviews) a recalculation from today's records.
    private static function entry(PerformanceReview $r, Employee $e, string $by): array
    {
        $start = $r->period_start->toDateString();
        $end = $r->period_end->toDateString();

        if (is_array($r->snapshot)) {
            $d = $r->snapshot;
            $source = 'snapshot';
            $rating = $r->published_rating ?? ($d['rating'] ?? null);
            $label = $d['rating_label'] ?? PerformanceScore::label($rating);
        } else {
            $row = PerformanceScore::forPeriod($start, $end, collect([$e]))[$e->id];
            $d = Arr::only($row, PerformanceReview::SNAPSHOT_KEYS);
            $source = 'recalculated';
            $rating = $row['rating'];
            $label = $row['rating_label'];
        }

        $pub = $r->publisher;

        return [
            'id' => $r->id,
            'period_start' => $start,
            'period_end' => $end,
            'bucket' => self::bucketOf($end, $by)['key'],
            'rating' => $rating === null ? null : (float) $rating,
            'rating_label' => $label,
            'source' => $source,
            'manual' => $r->final_rating !== null, // Admin typed this rating
            'work_days' => $d['work_days'] ?? null,
            'present_days' => $d['present_days'] ?? null,
            'attendance_rate' => $d['attendance_rate'] ?? null,
            'punctuality_rate' => $d['punctuality_rate'] ?? null,
            'goal_target' => $d['goal_target'] ?? null,
            'goal_done' => $d['goal_done'] ?? null,
            'goal_completion' => $d['goal_completion'] ?? null,
            'remarks' => $r->remarks,
            'published_at' => $r->published_at?->toIso8601String(),
            'reviewer' => $pub ? ['name' => $pub->name, 'role' => $pub->sub_role ?: $pub->role] : null,
        ];
    }

    // The live work records behind the goal numbers, newest first. They come
    // straight from the source tables, so they can differ from a published
    // snapshot when records changed after publishing. Only roles with a work
    // goal have records; the board's "owner" is free text and is not used.
    private static function records(Employee $e, ?string $role, string $from, string $to, string $by): array
    {
        $out = ['items' => [], 'truncated' => false];
        if (! $role || ! $e->user_id) {
            return $out;
        }
        $uid = $e->user_id;
        $between = [$from.' 00:00:00', $to.' 23:59:59'];
        $cap = self::RECORD_LIMIT;
        $rows = collect();
        $add = function ($coll, callable $map) use (&$rows, $cap, &$out) {
            if ($coll->count() >= $cap) {
                $out['truncated'] = true;
            }
            foreach ($coll as $x) {
                $rows->push($map($x));
            }
        };

        if ($role === 'driver') {
            $add(Delivery::with('customer:id,name')->where('driver_id', $uid)->where('status', 'delivered')->whereBetween('delivered_at', $between)
                ->orderByDesc('delivered_at')->limit($cap)->get(),
                fn ($d) => ['at' => $d->delivered_at, 'type' => 'Delivery completed', 'ref' => $d->delivery_no, 'detail' => $d->customer?->name, 'to' => '/admin/deliveries']);
        } elseif ($role === 'salesman') {
            $add(DeliveryProof::with('delivery.customer:id,name')->where('salesman_id', $uid)->whereBetween('confirmed_at', $between)
                ->orderByDesc('confirmed_at')->limit($cap)->get(),
                fn ($p) => ['at' => $p->confirmed_at, 'type' => 'Delivery confirmed', 'ref' => $p->delivery?->delivery_no, 'detail' => trim(($p->delivery?->customer?->name ?? '').' · '.$p->quantity.' pcs', ' ·'), 'to' => '/admin/deliveries']);
        } elseif ($role === 'checker') {
            $add(IceLoading::with('delivery:id,delivery_no')->where('checker_id', $uid)->whereBetween('loaded_at', $between)
                ->orderByDesc('loaded_at')->limit($cap)->get(),
                fn ($l) => ['at' => $l->loaded_at, 'type' => 'Ice loading', 'ref' => $l->delivery?->delivery_no, 'detail' => $l->product_type.' × '.$l->quantity_loaded, 'to' => '/admin/deliveries']);
            $add(StockMovement::with('product:id,name')->where('entered_by', $uid)->whereBetween('created_at', $between)
                ->orderByDesc('created_at')->limit($cap)->get(),
                fn ($m) => ['at' => $m->created_at, 'type' => 'Stock movement', 'ref' => $m->reference_no, 'detail' => ucfirst((string) $m->type).' '.$m->quantity.' · '.($m->product?->name ?? 'product removed'), 'to' => null]);
        } elseif ($role === 'maintenance') {
            $add(StockOutRequest::with('product:id,name')->where('requested_by', $uid)->whereBetween('created_at', $between)
                ->orderByDesc('created_at')->limit($cap)->get(),
                fn ($r) => ['at' => $r->created_at, 'type' => 'Stock-out request', 'ref' => '#'.$r->id, 'detail' => ($r->item_name ?: $r->product?->name).' · '.$r->quantity.' · '.$r->status, 'to' => '/admin/stock-out-approvals']);
            $add(Project::where('created_by', $uid)->whereBetween('created_at', $between)->orderByDesc('created_at')->limit($cap)->get(),
                fn ($p) => ['at' => $p->created_at, 'type' => 'Project created', 'ref' => $p->name, 'detail' => ucfirst($p->status).($p->scheduleProgress() !== null ? ' · schedule progress '.$p->scheduleProgress().'% (time elapsed, not work done)' : ''), 'to' => '/admin/projects']);
        }

        $out['items'] = $rows->sortByDesc(fn ($r) => (string) $r['at'])->take($cap)->values()->map(fn ($r) => [
            'at' => Carbon::parse($r['at'])->format('Y-m-d H:i'),
            'bucket' => self::bucketOf(Carbon::parse($r['at'])->toDateString(), $by)['key'],
            'bucket_label' => self::bucketOf(Carbon::parse($r['at'])->toDateString(), $by)['label'],
            'type' => $r['type'],
            'ref' => $r['ref'],
            'detail' => $r['detail'],
            'to' => $r['to'],
        ])->all();
        if (count($out['items']) >= $cap) {
            $out['truncated'] = true;
        }

        return $out;
    }

    /** @return array<int, array{key:string,label:string,start:string,end:string}> */
    private static function buckets(string $from, string $to, string $by): array
    {
        $out = [];
        $step = $by === 'month' ? 1 : 3;
        $d = $by === 'month' ? Carbon::parse($from)->startOfMonth() : Carbon::parse($from)->firstOfQuarter();
        $last = Carbon::parse($to);
        while ($d->lte($last)) {
            $out[] = self::bucketOf($d->toDateString(), $by);
            $d = $d->copy()->addMonthsNoOverflow($step);
        }

        return $out;
    }

    private static function bucketOf(string $date, string $by): array
    {
        $d = Carbon::parse($date);
        if ($by === 'month') {
            return ['key' => $d->format('Y-m'), 'label' => $d->format('M Y'), 'start' => $d->copy()->startOfMonth()->toDateString(), 'end' => $d->copy()->endOfMonth()->toDateString()];
        }

        return [
            'key' => $d->year.'-Q'.$d->quarter,
            'label' => 'Q'.$d->quarter.' '.$d->year,
            'start' => $d->copy()->firstOfQuarter()->toDateString(),
            'end' => $d->copy()->lastOfQuarter()->toDateString(),
        ];
    }
}
