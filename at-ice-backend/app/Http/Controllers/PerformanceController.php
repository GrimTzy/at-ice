<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\PerformanceReview;
use App\Support\Audit;
use App\Support\DriverAvailability;
use App\Support\PayPeriod;
use App\Support\PerformanceHistory;
use App\Support\PerformanceScore;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Admin > HRIS > Performance (owner, 2026-10-20): every active employee's
// Draft Base Rating for a pay period (App\Support\PerformanceScore), with
// Admin's overrides. Only Admin reads and writes it (role:admin routes).
class PerformanceController extends Controller
{
    // The last 12 pay periods, newest first.
    private function periods(): array
    {
        $out = [];
        $date = DriverAvailability::today();
        for ($i = 0; $i < 12; $i++) {
            $p = PayPeriod::for($date);
            $out[] = ['start' => $p['start'], 'end' => $p['end']];
            $date = Carbon::parse($p['start'])->subDay()->toDateString();
        }

        return $out;
    }

    private function period(Request $request): array
    {
        $start = $request->query('period_start');
        $p = PayPeriod::for($start && strtotime($start) ? $start : DriverAvailability::today());

        return ['start' => $p['start'], 'end' => $p['end']];
    }

    // GET /api/performance?period_start=
    public function index(Request $request)
    {
        $period = $this->period($request);
        $employees = Employee::with('user:id,role,sub_role')->where('is_active', true)->whereNull('anonymized_at')
            ->orderBy('last_name')->orderBy('first_name')->get();

        return response()->json([
            'period' => $period,
            'periods' => $this->periods(),
            'labels' => array_column(PerformanceScore::LABELS, 1),
            'rows' => array_values(PerformanceScore::forPeriod($period['start'], $period['end'], $employees)),
        ]);
    }

    // GET /api/performance/{employee}/history?from=&to=  read-only history of one
    // employee: published ratings by quarter, attendance, goals and feedback.
    public function history(Request $request, Employee $employee)
    {
        abort_if($employee->anonymized_at, 404);
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'by' => 'nullable|in:month,quarter',
        ]);
        $by = $data['by'] ?? 'quarter';
        $default = PerformanceHistory::defaultRange($by);
        $from = $data['from'] ?? $default['from'];
        $to = $data['to'] ?? max($from, $default['to']);
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }
        if (Carbon::parse($from)->addYears(PerformanceHistory::MAX_YEARS)->lt(Carbon::parse($to))) {
            throw ValidationException::withMessages(['to' => 'Choose a range of '.PerformanceHistory::MAX_YEARS.' years or less.']);
        }

        return response()->json(PerformanceHistory::build($employee, $from, $to, $by));
    }

    // GET /api/performance/{employee}/photo  the employee's 2x2 photo (private disk), if any
    public function photo(Employee $employee)
    {
        abort_if($employee->anonymized_at, 404);
        $doc = EmployeeDocument::where('employee_id', $employee->id)->where('type', 'photo')->latest('id')->first();
        abort_unless($doc && Storage::disk('local')->exists($doc->path), 404);

        return Storage::disk('local')->response($doc->path, $doc->original_name, [
            'Content-Type' => $doc->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    // PUT /api/performance/{employee}?period_start=  Admin overrides the rating
    // or the goal, writes remarks, and may publish (published is final until
    // Admin changes it again; every change is audited).
    public function update(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'final_rating' => 'nullable|numeric|between:1,5',
            'goal_target' => 'nullable|integer|min:1|max:100000',
            'remarks' => 'nullable|string|max:2000',
            'publish' => 'sometimes|boolean',
        ]);
        $period = $this->period($request);

        $employee->load('user:id,role,sub_role');
        $tracked = ['final_rating', 'goal_target', 'remarks', 'status', 'published_rating'];

        // Review, snapshot and audit entry are written together.
        [$review, $row] = DB::transaction(function () use ($request, $data, $period, $employee, $tracked) {
            // whereDate: SQLite stores the date cast with a time, so an equality match on the plain date would miss the row
            $review = PerformanceReview::where('employee_id', $employee->id)->whereDate('period_start', $period['start'])->first()
                ?? new PerformanceReview(['employee_id' => $employee->id, 'period_start' => $period['start']]);
            $before = $review->exists ? $review->only($tracked) : null;
            $review->fill([
                'period_end' => $period['end'],
                'final_rating' => isset($data['final_rating']) ? round((float) $data['final_rating'], 1) : null,
                'goal_target' => $data['goal_target'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'updated_by' => $request->user()->id,
            ]);
            if ($request->boolean('publish')) {
                $review->fill(['status' => 'published', 'published_by' => $request->user()->id, 'published_at' => now()]);
            } elseif (! $review->exists || $review->status !== 'published') {
                $review->status = 'draft';
            }
            $review->save();

            // The row is worked out from the review as just saved. A published
            // appraisal keeps that row as its snapshot, also when it is edited
            // again later (the edit is the new published state).
            $row = PerformanceScore::forPeriod($period['start'], $period['end'], collect([$employee]))[$employee->id];
            if ($review->status === 'published') {
                $review->update([
                    'published_rating' => $row['rating'],
                    'snapshot' => Arr::only($row, PerformanceReview::SNAPSHOT_KEYS),
                    'snapshot_at' => now(),
                ]);
            }

            Audit::log($request->boolean('publish') ? 'performance.published' : 'performance.updated', $review, $before, $review->only($tracked));

            return [$review, $row];
        });

        return response()->json([
            'message' => $request->boolean('publish') ? "Appraisal of {$employee->full_name} published." : "Appraisal of {$employee->full_name} saved.",
            'row' => $row,
        ]);
    }
}
