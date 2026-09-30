<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Receipt;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Expense;
use Carbon\Carbon;

class BiController extends Controller
{
    // The app runs in UTC; "today" for attendance must be Philippine time.
    private const LOCAL_TZ = 'Asia/Manila';

    // Receipts unpaid for longer than this many days count as overdue
    // (same rule as ReceiptController@overdue).
    private const OVERDUE_DAYS = 15;

    // GET /api/bi/sales-overview
    // Returns: monthly sales totals for current year (all 12 months),
    // plus prev month and same month last year for comparison
    public function salesOverview()
    {
        $now        = Carbon::now();
        $thisYear   = $now->year;
        $lastYear   = $thisYear - 1;
        $thisMonth  = $now->month;
        $prevMonth  = $now->copy()->subMonth()->month;
        $prevMonthYear = $now->copy()->subMonth()->year;

        // Monthly totals, current year (for bar/line chart)
        $thisYearMonthly = Receipt::selectRaw('MONTH(created_at) as month, SUM(total_amount) as total')
            ->whereYear('created_at', $thisYear)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        // Monthly totals, last year (for comparison chart)
        $lastYearMonthly = Receipt::selectRaw('MONTH(created_at) as month, SUM(total_amount) as total')
            ->whereYear('created_at', $lastYear)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        // Build 12-month arrays
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = [
                'month'      => $m,
                'month_name' => Carbon::create()->month($m)->format('M'),
                'this_year'  => isset($thisYearMonthly[$m]) ? (float) $thisYearMonthly[$m]->total : 0,
                'last_year'  => isset($lastYearMonthly[$m]) ? (float) $lastYearMonthly[$m]->total : 0,
            ];
        }

        // Current month total
        $thisMonthTotal = Receipt::whereYear('created_at', $thisYear)
            ->whereMonth('created_at', $thisMonth)
            ->sum('total_amount');

        // Previous month total
        $prevMonthTotal = Receipt::whereYear('created_at', $prevMonthYear)
            ->whereMonth('created_at', $prevMonth)
            ->sum('total_amount');

        // Same month last year
        $sameMonthLastYear = Receipt::whereYear('created_at', $lastYear)
            ->whereMonth('created_at', $thisMonth)
            ->sum('total_amount');

        // Month-over-month % change
        $momChange = $prevMonthTotal > 0
            ? round((($thisMonthTotal - $prevMonthTotal) / $prevMonthTotal) * 100, 1)
            : null;

        // YoY % change
        $yoyChange = $sameMonthLastYear > 0
            ? round((($thisMonthTotal - $sameMonthLastYear) / $sameMonthLastYear) * 100, 1)
            : null;

        // Total paid vs unpaid this month
        $paidThisMonth = Receipt::whereYear('created_at', $thisYear)
            ->whereMonth('created_at', $thisMonth)
            ->where('payment_status', 'paid')
            ->sum('total_amount');

        $unpaidThisMonth = Receipt::whereYear('created_at', $thisYear)
            ->whereMonth('created_at', $thisMonth)
            ->whereIn('payment_status', ['unpaid', 'overdue'])
            ->sum('total_amount');

        // Total orders this month (receipt count)
        $totalOrders = Receipt::whereYear('created_at', $thisYear)
            ->whereMonth('created_at', $thisMonth)
            ->count();

        $avgOrderValue = $totalOrders > 0 ? round($thisMonthTotal / $totalOrders, 2) : 0;

        return response()->json([
            'monthly_chart'       => $months,
            'this_month_total'    => (float) $thisMonthTotal,
            'prev_month_total'    => (float) $prevMonthTotal,
            'same_month_last_year'=> (float) $sameMonthLastYear,
            'mom_change_pct'      => $momChange,
            'yoy_change_pct'      => $yoyChange,
            'paid_this_month'     => (float) $paidThisMonth,
            'unpaid_this_month'   => (float) $unpaidThisMonth,
            'total_orders'        => $totalOrders,
            'avg_order_value'     => $avgOrderValue,
            'current_month'       => $now->format('F Y'),
        ]);
    }

    // GET /api/bi/delivery-stats
    // Returns: delivery counts by status + this month vs last month comparison
    public function deliveryStats()
    {
        $now       = Carbon::now();
        $thisYear  = $now->year;
        $thisMonth = $now->month;

        // All-time counts by status
        $statusCounts = Delivery::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $delivered  = isset($statusCounts['delivered'])  ? (int) $statusCounts['delivered']->count  : 0;
        $inTransit  = isset($statusCounts['in_transit'])  ? (int) $statusCounts['in_transit']->count  : 0;
        $pending    = isset($statusCounts['pending'])    ? (int) $statusCounts['pending']->count    : 0;
        $cancelled  = isset($statusCounts['cancelled'])  ? (int) $statusCounts['cancelled']->count  : 0;
        $total      = $delivered + $inTransit + $pending + $cancelled;

        // This month
        $thisMonthTotal = Delivery::whereYear('created_at', $thisYear)
            ->whereMonth('created_at', $thisMonth)
            ->count();

        // Last month
        $lastMonth      = $now->copy()->subMonth();
        $lastMonthTotal = Delivery::whereYear('created_at', $lastMonth->year)
            ->whereMonth('created_at', $lastMonth->month)
            ->count();

        $momChange = $lastMonthTotal > 0
            ? round((($thisMonthTotal - $lastMonthTotal) / $lastMonthTotal) * 100, 1)
            : null;

        // Delivery rate (delivered / total this month)
        $thisMonthDelivered = Delivery::whereYear('created_at', $thisYear)
            ->whereMonth('created_at', $thisMonth)
            ->where('status', 'delivered')
            ->count();

        $deliveryRate = $thisMonthTotal > 0
            ? round(($thisMonthDelivered / $thisMonthTotal) * 100, 1)
            : 0;

        return response()->json([
            'total'             => $total,
            'delivered'         => $delivered,
            'in_transit'        => $inTransit,
            'pending'           => $pending,
            'cancelled'         => $cancelled,
            'this_month_total'  => $thisMonthTotal,
            'last_month_total'  => $lastMonthTotal,
            'mom_change_pct'    => $momChange,
            'delivery_rate_pct' => $deliveryRate,
        ]);
    }

    // GET /api/bi/inventory-summary
    // Returns: in stock / low stock / out of stock counts from products table
    // (supplies and materials only)
    public function inventorySummary()
    {
        $products = Product::where('is_active', true)->get();

        $inStock    = $products->filter(fn($p) => $p->current_stock > $p->minimum_stock)->count();
        $lowStock   = $products->filter(fn($p) => $p->current_stock > 0 && $p->current_stock <= $p->minimum_stock)->count();
        $outOfStock = $products->filter(fn($p) => $p->current_stock === 0)->count();
        $total      = $products->count();

        // Critical items (out of stock or low stock), top 5
        $criticalItems = $products
            ->filter(fn($p) => $p->current_stock <= $p->minimum_stock)
            ->sortBy('current_stock')
            ->take(5)
            ->map(fn($p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'product_code'  => $p->product_code,
                'current_stock' => $p->current_stock,
                'minimum_stock' => $p->minimum_stock,
                'unit'          => $p->unit,
                'status'        => $p->current_stock === 0 ? 'out_of_stock' : 'low_stock',
            ])
            ->values();

        return response()->json([
            'total'          => $total,
            'in_stock'       => $inStock,
            'low_stock'      => $lowStock,
            'out_of_stock'   => $outOfStock,
            'critical_items' => $criticalItems,
        ]);
    }

    // GET /api/bi/attendance-today
    // Returns: today's attendance summary. "Today" is Philippine time
    // (the app runs in UTC, which is 8 hours behind).
    public function attendanceToday()
    {
        $now   = Carbon::now(self::LOCAL_TZ);
        $today = $now->toDateString();

        // Count by status for today
        $statusCounts = Attendance::whereDate('date', $today)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $present  = isset($statusCounts['present'])   ? (int) $statusCounts['present']->count   : 0;
        $late     = isset($statusCounts['late'])      ? (int) $statusCounts['late']->count      : 0;
        $absent   = isset($statusCounts['absent'])    ? (int) $statusCounts['absent']->count    : 0;
        $halfDay  = isset($statusCounts['half_day'])  ? (int) $statusCounts['half_day']->count  : 0;
        $onLeave  = isset($statusCounts['on_leave'])  ? (int) $statusCounts['on_leave']->count  : 0;

        // Total active employees
        $totalEmployees = Employee::count();

        // Present + late count as "in"
        $totalIn = $present + $late + $halfDay;

        $attendanceRate = $totalEmployees > 0
            ? round(($totalIn / $totalEmployees) * 100, 1)
            : 0;

        // Weekly trend (last 7 days) for mini chart
        $weeklyTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day   = $now->copy()->subDays($i);
            $count = Attendance::whereDate('date', $day->toDateString())
                ->whereIn('status', ['present', 'late', 'half_day'])
                ->count();
            $weeklyTrend[] = [
                'date'  => $day->format('D'),
                'count' => $count,
            ];
        }

        return response()->json([
            'date'            => $today,
            'total_employees' => $totalEmployees,
            'present'         => $present,
            'late'            => $late,
            'absent'          => $absent,
            'half_day'        => $halfDay,
            'on_leave'        => $onLeave,
            'total_in'        => $totalIn,
            'attendance_rate' => $attendanceRate,
            'weekly_trend'    => $weeklyTrend,
        ]);
    }

    // GET /api/bi/expenses
    // Returns: all expenses, grouped by month
    public function getExpenses(Request $request)
    {
        $year = $request->query('year', Carbon::now()->year);

        $expenses = Expense::whereYear('expense_date', $year)
            ->orderBy('expense_date', 'desc')
            ->get()
            ->groupBy(fn($e) => Carbon::parse($e->expense_date)->format('Y-m'));

        // Build monthly summary for chart
        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $key          = Carbon::create($year, $m)->format('Y-m');
            $monthData    = $expenses->get($key, collect());
            $monthly[] = [
                'month'       => $m,
                'month_name'  => Carbon::create()->month($m)->format('M'),
                'fuel'        => (float) $monthData->where('category', 'fuel')->sum('amount'),
                'water'       => (float) $monthData->where('category', 'water')->sum('amount'),
                'electricity' => (float) $monthData->where('category', 'electricity')->sum('amount'),
                'others'      => (float) $monthData->where('category', 'others')->sum('amount'),
                'total'       => (float) $monthData->sum('amount'),
            ];
        }

        // Year totals
        $allThisYear = Expense::whereYear('expense_date', $year)->get();

        return response()->json([
            'year'          => (int) $year,
            'monthly_chart' => $monthly,
            'year_total'    => (float) $allThisYear->sum('amount'),
            'fuel_total'    => (float) $allThisYear->where('category', 'fuel')->sum('amount'),
            'water_total'   => (float) $allThisYear->where('category', 'water')->sum('amount'),
            'electricity_total' => (float) $allThisYear->where('category', 'electricity')->sum('amount'),
            'others_total'  => (float) $allThisYear->where('category', 'others')->sum('amount'),
        ]);
    }

    // POST /api/bi/expenses
    // Admin manually inputs expense (fuel, water, electricity, others)
    // Body: { category, amount, expense_date, notes }
    public function storeExpense(Request $request)
    {
        $validated = $request->validate([
            'category'     => 'required|in:fuel,water,electricity,others',
            'amount'       => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'notes'        => 'nullable|string|max:500',
        ]);

        $expense = Expense::create($validated);

        return response()->json([
            'message' => 'Expense recorded successfully.',
            'expense' => $expense,
        ], 201);
    }

    // GET /api/bi/overdue-receipts
    // Returns: receipts that are overdue. Counts BOTH receipts already marked
    // "overdue" (the Receipts page marks them) AND unpaid receipts older than
    // 15 days that have not been marked yet. Previously only "unpaid" was
    // counted, so the card showed 0 once the Receipts page had marked them.
    public function overdueReceipts()
    {
        $cutoff = Carbon::now()->subDays(self::OVERDUE_DAYS);

        $overdue = Receipt::with(['delivery.customer'])
            ->where(function ($q) use ($cutoff) {
                $q->where('payment_status', 'overdue')
                  ->orWhere(function ($q2) use ($cutoff) {
                      $q2->where('payment_status', 'unpaid')
                         ->where('created_at', '<=', $cutoff);
                  });
            })
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn($r) => [
                'id'             => $r->id,
                'receipt_no'     => $r->receipt_no,
                'total_amount'   => (float) $r->total_amount,
                'created_at'     => $r->created_at->toDateString(),
                'days_overdue'   => (int) $r->created_at->diffInDays(Carbon::now()),
                'customer_name'  => $r->delivery?->customer?->name ?? 'N/A',
                'customer_id'    => $r->customer_id,
            ]);

        return response()->json([
            'total_overdue_amount' => (float) $overdue->sum('total_amount'),
            'overdue_count'        => $overdue->count(),
            'overdue_customers'    => $overdue->pluck('customer_id')->filter()->unique()->count(),
            'receipts'             => $overdue->values(),
        ]);
    }

    // GET /api/bi/summary
    // Single endpoint - returns ALL BI data in one call
    public function summary()
    {
        $expenseRequest = new \Illuminate\Http\Request();

        return response()->json([
            'sales'      => json_decode($this->salesOverview()->content(), true),
            'deliveries' => json_decode($this->deliveryStats()->content(), true),
            'inventory'  => json_decode($this->inventorySummary()->content(), true),
            'attendance' => json_decode($this->attendanceToday()->content(), true),
            'expenses'   => json_decode($this->getExpenses($expenseRequest)->content(), true),
            'overdue'    => json_decode($this->overdueReceipts()->content(), true),
        ]);
    }

    // If a reply was cut off by the token limit, keep only complete
    // sentences so the dashboard never shows a half sentence.
    private function trimToCompleteSentence(string $text): string
    {
        $text = trim($text);

        if ($text === '' || preg_match('/[.!?]["\')\]]?$/u', $text)) {
            return $text;
        }

        $lastEnd = max(
            (int) mb_strrpos($text, '. '),
            (int) mb_strrpos($text, '! '),
            (int) mb_strrpos($text, '? ')
        );

        return $lastEnd > 0 ? mb_substr($text, 0, $lastEnd + 1) : $text;
    }

    // GET /api/bi/ai-summary
    public function aiSummary()
    {
        $data = json_decode($this->summary()->content(), true);

        $prompt = "You are a business analyst AI for Bella Erin Tube Ice Inc., an ice manufacturing company in the Philippines.
    Based on this business data, write a SHORT 2-3 sentence executive summary in plain English. Be specific with numbers. Use Philippine Peso (₱).

    Sales this month: ₱" . number_format($data['sales']['this_month_total']) . "
    Month-over-month change: " . ($data['sales']['mom_change_pct'] ?? 'N/A') . "%
    Total deliveries: " . $data['deliveries']['total'] . " (" . $data['deliveries']['delivery_rate_pct'] . "% delivery rate)
    Inventory: " . $data['inventory']['out_of_stock'] . " out of stock, " . $data['inventory']['low_stock'] . " low stock items
    Attendance today: " . $data['attendance']['total_in'] . " / " . $data['attendance']['total_employees'] . " employees present
    Overdue receivables: ₱" . number_format($data['overdue']['total_overdue_amount']) . " across " . $data['overdue']['overdue_customers'] . " customer(s)

    Respond with ONLY the summary paragraph, no labels or headers.";

        // Read the key through config (env() returns null in production once
        // the config is cached). Falls back to env() for local development.
        $apiKey = config('services.groq.key') ?: env('GROQ_API_KEY');

        if (!$apiKey) {
            return response()->json(['summary' => 'AI summary unavailable at this time.']);
        }

        try {
            $http = Http::timeout(30)
                ->withHeaders(['Authorization' => "Bearer {$apiKey}"]);

            // SSL verification is skipped ONLY on the local machine (old
            // Windows cURL certificate issue). Production always verifies.
            if (app()->environment('local')) {
                $http = $http->withoutVerifying();
            }

            $response = $http->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'       => 'openai/gpt-oss-20b',
                'messages'    => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                // gpt-oss is a reasoning model: part of the budget is used for
                // its hidden "thinking" before the answer. 300 was too small
                // and cut the summary mid-sentence.
                'max_tokens'  => 1024,
                'temperature' => 0.7,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Groq AI summary request failed', ['error' => $e->getMessage()]);
            return response()->json(['summary' => 'AI summary unavailable at this time.']);
        }

        if ($response->failed()) {
            // Details go to the server log only, never to the browser
            Log::warning('Groq AI summary returned an error', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);
            return response()->json(['summary' => 'AI summary unavailable at this time.']);
        }

        $text = $response->json('choices.0.message.content');

        if (!is_string($text) || trim($text) === '') {
            return response()->json(['summary' => 'AI summary unavailable at this time.']);
        }

        return response()->json(['summary' => $this->trimToCompleteSentence($text)]);
    }
}