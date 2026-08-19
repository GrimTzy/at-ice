<?php

namespace App\Http\Controllers;

use App\Models\FaceData;
use Illuminate\Http\Request;

class FaceDataController extends Controller
{
    // HR enrolls a worker's face
    // POST /api/face-enroll
    public function enroll(Request $request)
    {
        $validated = $request->validate([
            'worker_name'  => 'required|string|max:255',
            'role'         => 'required|in:driver,salesman,checker,maintenance,guard,bagger',
            'employee_id'  => 'nullable|exists:employees,id',
            'descriptor'   => 'required|array|size:128',
            'descriptor.*' => 'numeric',
        ]);

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
    public function destroy($id)
    {
        FaceData::findOrFail($id)->delete();
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