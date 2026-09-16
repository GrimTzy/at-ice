<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use Illuminate\Http\Request;

class ApplicantController extends Controller
{
    // GET all applicants (HR)
    public function index(Request $request)
    {
        $query = Applicant::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('position_applied')) {
            $query->where('position_applied', $request->position_applied);
        }

        $applicants = $query->orderBy('interview_date', 'asc')->get();

        return response()->json($applicants);
    }

    // GET single applicant
    public function show($id)
    {
        $applicant = Applicant::findOrFail($id);
        return response()->json($applicant);
    }

    // CREATE applicant + schedule interview (HR)
    public function store(Request $request)
    {
        $request->validate([
            'name'             => 'required|string|max:255',
            'email'            => 'nullable|email',
            'phone'            => 'nullable|string|max:20',
            'position_applied' => 'required|string|max:255',
            'interview_date'   => 'nullable|date|after:now',
            'notes'            => 'nullable|string',
        ]);

        $applicant = Applicant::create($request->all());

        return response()->json([
            'message'   => 'Applicant added successfully!',
            'applicant' => $applicant,
        ], 201);
    }

    // UPDATE applicant / reschedule interview (HR)
    public function update(Request $request, $id)
    {
        $applicant = Applicant::findOrFail($id);

        $request->validate([
            'name'             => 'sometimes|string|max:255',
            'email'            => 'nullable|email',
            'phone'            => 'nullable|string|max:20',
            'position_applied' => 'sometimes|string|max:255',
            'interview_date'   => 'nullable|date',
            'status'           => 'sometimes|in:pending,interviewed,hired,rejected',
            'notes'            => 'nullable|string',
        ]);

        $applicant->update($request->all());

        return response()->json([
            'message'   => 'Applicant updated successfully!',
            'applicant' => $applicant,
        ]);
    }

    // UPDATE status only (HR - quick action)
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,interviewed,hired,rejected',
        ]);

        $applicant = Applicant::findOrFail($id);
        $applicant->update(['status' => $request->status]);

        return response()->json([
            'message'   => "Applicant marked as {$request->status}.",
            'applicant' => $applicant,
        ]);
    }

    // DELETE applicant (HR)
    public function destroy($id)
    {
        $applicant = Applicant::findOrFail($id);
        $applicant->delete();

        return response()->json(['message' => 'Applicant deleted successfully.']);
    }

    // GET upcoming interviews (HR Dashboard)
    public function upcoming()
    {
        $applicants = Applicant::where('status', 'pending')
            ->whereNotNull('interview_date')
            ->where('interview_date', '>=', now())
            ->orderBy('interview_date', 'asc')
            ->get();

        return response()->json($applicants);
    }
}