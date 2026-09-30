<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ApplicantController extends Controller
{
    private const LOCAL_TZ = 'Asia/Manila';

    private function nowLocal(): string
    {
        return now(self::LOCAL_TZ)->format('Y-m-d H:i:s');
    }

    private function normalizeDate($value): ?string
    {
        if (!$value) {
            return null;
        }
        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }

    // GET all applicants (HR)
    public function index(Request $request)
    {
        $query = Applicant::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('position_applied')) {
            $query->where('position_applied', $request->position_applied);
        }

        $applicants = $query
            ->orderByRaw('interview_date IS NULL')   // scheduled first
            ->orderBy('interview_date', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($applicants);
    }

    // GET single applicant
    public function show($id)
    {
        $applicant = Applicant::findOrFail($id);
        return response()->json($applicant);
    }

    // CREATE applicant + schedule interview (HR)
    // New applicants always start as "pending".
    public function store(Request $request)
    {
        $request->validate([
            'name'             => 'required|string|max:255',
            'email'            => 'nullable|email|max:255',
            'phone'            => 'nullable|string|max:20',
            'position_applied' => 'required|string|max:255',
            'interview_date'   => 'nullable|date',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $interviewDate = $this->normalizeDate($request->interview_date);

        if ($interviewDate && $interviewDate <= $this->nowLocal()) {
            throw ValidationException::withMessages([
                'interview_date' => ['The interview date and time must be in the future.'],
            ]);
        }

        $applicant = Applicant::create([
            'name'             => $request->name,
            'email'            => $request->email,
            'phone'            => $request->phone,
            'position_applied' => $request->position_applied,
            'interview_date'   => $interviewDate,
            'status'           => 'pending',
            'notes'            => $request->notes,
        ]);

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
            'name'             => 'sometimes|required|string|max:255',
            'email'            => 'nullable|email|max:255',
            'phone'            => 'nullable|string|max:20',
            'position_applied' => 'sometimes|required|string|max:255',
            'interview_date'   => 'nullable|date',
            'status'           => 'sometimes|in:pending,interviewed,hired,rejected',
            'notes'            => 'nullable|string|max:1000',
        ]);

        $data = $request->only(['name', 'email', 'phone', 'position_applied', 'status', 'notes']);

        if ($request->exists('interview_date')) {
            $data['interview_date'] = $this->normalizeDate($request->interview_date);
        }

        $applicant->update($data);

        return response()->json([
            'message'   => 'Applicant updated successfully!',
            'applicant' => $applicant->fresh(),
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
            'applicant' => $applicant->fresh(),
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
            ->where('interview_date', '>=', $this->nowLocal())
            ->orderBy('interview_date', 'asc')
            ->get();

        return response()->json($applicants);
    }
}