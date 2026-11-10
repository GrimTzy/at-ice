<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\Delivery;
use App\Models\EmployeeMemo;
use App\Models\LeaveRequest;
use App\Models\StockOutRequest;
use App\Models\SupportMessage;
use Illuminate\Http\JsonResponse;

// Counts for the blue badges in the side menu: how many items wait for the
// signed-in role on each page. Only plain counts, keyed by the page path the
// menu links to (lib/nav.jsx), so the web app needs no mapping of its own.
class NavCountController extends Controller
{
    public function admin(): JsonResponse
    {
        return response()->json([
            '/admin/stock-out-approvals' => StockOutRequest::where('status', 'pending')->count(),
            // Formal memos HR endorsed, waiting for approval
            '/admin/memo-approvals' => EmployeeMemo::where('status', 'for_approval')->count(),
            // Waiting deliveries (no driver): assign a driver or reschedule
            '/admin/deliveries' => Delivery::where('status', 'queued')->count(),
            // New tickets, plus escalated ones still being worked on
            '/admin/support-inbox' => SupportMessage::where('status', 'open')
                ->orWhere(fn ($q) => $q->whereNotNull('escalated_at')->where('status', 'in_progress'))
                ->count(),
        ]);
    }

    public function hr(): JsonResponse
    {
        return response()->json([
            '/hr/leave-requests' => LeaveRequest::where('status', 'pending')->count(),
            // Pending applicants with an interview still ahead
            '/hr/interviews' => Applicant::where('status', 'pending')
                ->whereNotNull('interview_date')
                ->where('interview_date', '>=', now()->startOfDay())
                ->count(),
        ]);
    }
}
