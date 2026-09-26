<?php

namespace App\Http\Controllers;

use App\Models\EmployeePerformance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmployeePerformanceController extends Controller
{
    // GET /api/employee-performance?employee_id=X
    public function index(Request $request)
    {
        $query = EmployeePerformance::with('reviewer:id,name');

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        return response()->json($query->orderBy('review_period_end', 'desc')->get(), 200);
    }

    // POST /api/employee-performance
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_id'          => 'required|exists:employees,id',
            'review_period_start'  => 'required|date',
            'review_period_end'    => 'required|date|after_or_equal:review_period_start',
            'rating'                => 'required|integer|min:1|max:5',
            'notes'                 => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $review = EmployeePerformance::create([
            'employee_id'         => $request->employee_id,
            'review_period_start' => $request->review_period_start,
            'review_period_end'   => $request->review_period_end,
            'rating'               => $request->rating,
            'notes'                => $request->notes ?? null,
            'reviewed_by'          => auth()->id(),
        ]);

        return response()->json(['message' => 'Performance review recorded.', 'data' => $review], 201);
    }

    // GET /api/employee-performance/{id}
    public function show($id)
    {
        $review = EmployeePerformance::with('reviewer:id,name')->find($id);
        if (!$review) return response()->json(['message' => 'Review not found.'], 404);
        return response()->json($review, 200);
    }

    // PUT /api/employee-performance/{id}
    public function update(Request $request, $id)
    {
        $review = EmployeePerformance::find($id);
        if (!$review) return response()->json(['message' => 'Review not found.'], 404);

        $review->update($request->only([
            'review_period_start', 'review_period_end', 'rating', 'notes'
        ]));

        return response()->json(['message' => 'Review updated.', 'data' => $review], 200);
    }

    // DELETE /api/employee-performance/{id}
    public function destroy($id)
    {
        $review = EmployeePerformance::find($id);
        if (!$review) return response()->json(['message' => 'Review not found.'], 404);

        $review->delete();
        return response()->json(['message' => 'Review deleted.'], 200);
    }
}