<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends Controller
{
    // GET all employees
    // NOTE (v29.7): scope widened to also include Driver accounts (role:
    // driver on the users table) alongside Checker/Salesman/Maintenance
    // (role: employee, sub_role in [...]). Drivers keep their own separate
    // login/routes (role:driver, /driver/login unchanged) — this filter
    // only controls who shows up on the Employees management page, not
    // how anyone logs in. Still excludes Admin/HR — see the original
    // defensive-filter note this replaces, same reasoning applies.
    public function index()
    {
        $employees = Employee::with('user')
            ->where('is_active', true)
            ->whereHas('user', function ($q) {
                $q->where(function ($q2) {
                    $q2->where('role', 'employee')
                       ->whereIn('sub_role', ['checker', 'salesman', 'maintenance']);
                })->orWhere('role', 'driver');
            })
            ->get();
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
            // 'driver' added (v29.7) — Driver is now manageable from this
            // page, but is NOT a real sub_role; see below, it maps to its
            // own top-level `role` instead so /driver/login and
            // role:driver routes keep working unchanged.
            'sub_role' => 'nullable|in:checker,salesman,maintenance,driver',
            // vehicle_plate: only meaningful for Driver, but validated
            // generically here — the frontend only shows/sends this field
            // when Employee Role is set to Driver.
            'vehicle_plate' => 'nullable|string|max:20',
        ]);

        $isDriver = $request->sub_role === 'driver';

        // Create user account
        $user = User::create([
            'name'      => $request->first_name . ' ' . $request->last_name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            // Driver keeps its own top-level role, not employee+sub_role —
            // this is what keeps /driver/login and role:driver routes
            // working exactly as before, with no changes needed there.
            'role'      => $isDriver ? 'driver' : 'employee',
            'sub_role'  => $isDriver ? null : ($request->sub_role ?? null),
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
            // Only ever meaningful for Driver rows; left null for everyone
            // else regardless of what the request sends.
            'vehicle_plate' => $isDriver ? $request->vehicle_plate : null,
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

        $request->validate([
            'vehicle_plate' => 'nullable|string|max:20',
        ]);

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
            'vehicle_plate',
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