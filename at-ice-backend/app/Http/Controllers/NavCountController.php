<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
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
            '/admin/support-inbox' => SupportMessage::where('status', 'open')->count(),
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
