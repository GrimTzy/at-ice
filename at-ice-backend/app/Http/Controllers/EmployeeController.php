<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends Controller
{
    // GET all employees
    public function index()
    {
        $employees = Employee::with('user')->where('is_active', true)->get();
        return response()->json($employees);
    }

    // POST create employee
    public function store(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'position' => 'required|string',
            'daily_rate' => 'required|numeric',
            'date_hired' => 'required|date',
            'shift' => 'required|in:day,night',
            'employment_type' => 'required|in:regular,contractual,part_time',
        ]);

        // Create user account
        $user = User::create([
            'name' => $request->first_name . ' ' . $request->last_name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'employee',
            'is_active' => true,
        ]);

        // Create employee record
        $employee = Employee::create([
            'user_id' => $user->id,
            'employee_id' => 'EMP-' . str_pad($user->id, 4, '0', STR_PAD_LEFT),
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'phone' => $request->phone,
            'address' => $request->address,
            'position' => $request->position,
            'department' => $request->department,
            'daily_rate' => $request->daily_rate,
            'date_hired' => $request->date_hired,
            'employment_type' => $request->employment_type,
            'shift' => $request->shift,
        ]);

        return response()->json([
            'message' => 'Employee created successfully!',
            'employee' => $employee
        ], 201);
    }

    // GET single employee
    public function show($id)
    {
        $employee = Employee::with('user')->findOrFail($id);
        return response()->json($employee);
    }

    // PUT update employee
    public function update(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);

        $employee->update($request->only([
            'first_name',
            'last_name',
            'phone',
            'address',
            'position',
            'department',
            'daily_rate',
            'shift',
            'employment_type',
        ]));

        return response()->json([
            'message' => 'Employee updated successfully!',
            'employee' => $employee
        ]);
    }

    // DELETE (soft delete — set is_active to false)
    public function destroy($id)
    {
        $employee = Employee::findOrFail($id);
        $employee->update(['is_active' => false]);

        return response()->json([
            'message' => 'Employee deactivated successfully!'
        ]);
    }
}