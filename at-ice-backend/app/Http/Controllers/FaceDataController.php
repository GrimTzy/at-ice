<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\FaceData;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FaceDataController extends Controller
{
    // Guards and Baggers have no system account, so they are enrolled
    // without an employee record. Every other role must be linked to one.
    private const NO_ACCOUNT_ROLES = ['guard', 'bagger'];

    // HR enrolls a worker's face
    // POST /api/face-enroll
    public function enroll(Request $request)
    {
        $validated = $request->validate([
            'worker_name'  => 'required|string|max:255',
            'role'         => 'required|in:driver,salesman,checker,maintenance,guard,bagger',
            'employee_id'  => [
                'nullable',
                'required_unless:role,guard,bagger',
                'prohibited_if:role,guard,bagger',
                'exists:employees,id',
                // One face per employee: stops a second enrollment that
                // would mix two people's attendance under one employee.
                Rule::unique('face_data', 'employee_id'),
            ],
            'descriptor'   => 'required|array|size:128',
            'descriptor.*' => 'numeric',
        ], [
            'employee_id.unique'          => 'This employee already has an enrolled face. Delete the existing enrollment first if it needs to be redone.',
            'employee_id.required_unless' => 'Select the employee for this worker.',
            'employee_id.prohibited_if'   => 'Guards and Baggers are enrolled without an employee account.',
            'employee_id.exists'          => 'The selected employee was not found.',
        ]);

        // The role must match the employee's actual role in the system
        if (!empty($validated['employee_id'])) {
            $employee = Employee::with('user')->find($validated['employee_id']);
            $actual   = $employee?->user?->role === 'driver'
                ? 'driver'
                : $employee?->user?->sub_role;

            if ($actual && $actual !== $validated['role']) {
                return response()->json([
                    'message' => "Role does not match this employee. {$employee->first_name} {$employee->last_name} is registered as " . ucfirst($actual) . '.',
                    'errors'  => ['role' => ['Role does not match the selected employee.']],
                ], 422);
            }
        }

        $faceData = FaceData::create($validated);

        return response()->json([
            'message'   => 'Face enrolled successfully.',
            'face_data' => $faceData,
        ], 201);
    }

    // List all enrolled faces
    // GET /api/face-data
    public function index()
    {
        return response()->json(FaceData::with('employee')->latest()->get());
    }

    // Re-enroll / update face descriptor
    // PUT /api/face-data/{id}
    public function update(Request $request, $id)
    {
        $faceData = FaceData::findOrFail($id);

        $validated = $request->validate([
            'descriptor'   => 'required|array|size:128',
            'descriptor.*' => 'numeric',
        ]);

        $faceData->update($validated);

        return response()->json([
            'message'   => 'Face data updated.',
            'face_data' => $faceData,
        ]);
    }

    // Delete enrolled face
    // DELETE /api/face-data/{id}
    // A face that already has attendance records is not deleted, so the
    // history stays linked to the right worker. HR re-enrolls it instead.
    public function destroy($id)
    {
        $faceData = FaceData::findOrFail($id);

        if (Attendance::where('face_data_id', $faceData->id)->exists()) {
            return response()->json([
                'message' => "{$faceData->worker_name} already has attendance records, so the enrollment cannot be deleted. Use Re-enroll to replace the face instead.",
            ], 422);
        }

        $faceData->delete();
        return response()->json(['message' => 'Face data deleted.']);
    }

    // Serve all descriptors to AttendanceStation for matching
    // GET /api/face-descriptors
    public function descriptors()
    {
        return response()->json(
            FaceData::select('id', 'worker_name', 'role', 'employee_id', 'descriptor')->get()
        );
    }
}