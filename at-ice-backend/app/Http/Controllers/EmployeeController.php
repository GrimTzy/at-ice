<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Services\AddressFormatter;
use App\Services\PhoneFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class EmployeeController extends Controller
{
    // Philippine mobile number: 11 digits starting with 09 (e.g. 09171234567)
    // Position => login role. Only positions that get a login account.
    private const POSITION_ROLES = [
        'Checker'     => 'checker',
        'Salesman'    => 'salesman',
        'Maintenance' => 'maintenance',
        'Driver'      => 'driver',
    ];

    private const PHONE_REGEX = PhoneFormatter::MOBILE_REGEX;
    private const PHONE_MESSAGE = PhoneFormatter::MOBILE_MESSAGE;

    // Stores the phone as +63XXXXXXXXXX. "0917 123 4567" and "+63 917 123 4567" are accepted.
    private function cleanPhone(Request $request): void
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => PhoneFormatter::normalize((string) $request->phone)]);
        }
    }

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
        $this->cleanPhone($request);

        $request->validate([
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            // At least 8 characters with uppercase, lowercase, a number and a special character.
            'password' => ['required', 'string', 'max:100', Password::min(8)->mixedCase()->numbers()->symbols()],
            'phone' => ['nullable', 'regex:' . self::PHONE_REGEX],
            'street' => 'required|string|max:255',
            'barangay' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'province' => 'required|string|max:255',
            'block_lot' => 'nullable|string|max:255',
            'subdivision' => 'nullable|string|max:255',
            'postal_code' => ['nullable', 'regex:/^\d{4}$/'],
            'position' => ['required', Rule::in(array_keys(self::POSITION_ROLES))],
            'daily_rate' => 'required|numeric',
            'date_hired' => 'required|date|before_or_equal:today',
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
        ], [
            'phone.regex' => self::PHONE_MESSAGE,
        ]);

        // The login role is derived from Position, so the two cannot disagree.
        // Any sub_role sent by the client is ignored.
        $subRole  = self::POSITION_ROLES[$request->position];
        $isDriver = $subRole === 'driver';

        // Create user account
        $user = User::create([
            'name'      => $request->first_name . ' ' . $request->last_name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            // Driver keeps its own top-level role, not employee+sub_role —
            // this is what keeps /driver/login and role:driver routes
            // working exactly as before, with no changes needed there.
            'role'      => $isDriver ? 'driver' : 'employee',
            'sub_role'  => $isDriver ? null : $subRole,
            'is_active' => true,
        ]);

        // Create employee record
        $employee = Employee::create([
            'user_id' => $user->id,
            'employee_id' => 'EMP-' . str_pad($user->id, 4, '0', STR_PAD_LEFT),
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'phone' => $request->phone,
            // One-line address rebuilt from the separate fields.
            'address' => AddressFormatter::compose($request->only(AddressFormatter::FIELDS)),
            'block_lot' => $request->block_lot,
            'street' => $request->street,
            'subdivision' => $request->subdivision,
            'barangay' => $request->barangay,
            'city' => $request->city,
            'province' => $request->province,
            'postal_code' => $request->postal_code,
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

        $this->cleanPhone($request);

        // Old employees have only the single free-text address and can be edited
        // without the new fields. But once any new address field is filled (or the
        // record already has them), Street, Barangay, City and Province are all
        // required, so the one-line address is never rebuilt from a partial set.
        $hasStructured = $employee->street !== null || $employee->barangay !== null;
        $addressRule   = $hasStructured
            ? 'required|string|max:255'
            : 'nullable|string|max:255|required_with:block_lot,street,subdivision,barangay,city,province,postal_code';

        $request->validate([
            'phone' => ['nullable', 'regex:' . self::PHONE_REGEX],
            'street' => $addressRule,
            'barangay' => $addressRule,
            'city' => $addressRule,
            'province' => $addressRule,
            'block_lot' => 'nullable|string|max:255',
            'subdivision' => 'nullable|string|max:255',
            'postal_code' => ['nullable', 'regex:/^\d{4}$/'],
            'vehicle_plate' => 'nullable|string|max:20',
            'date_hired' => 'sometimes|date|before_or_equal:today',
            // Position is tied to the login role, so it cannot be changed here.
            // An unchanged value is accepted, even an old one outside the list.
            'position' => [
                'sometimes',
                function ($attribute, $value, $fail) use ($employee) {
                    if ($value !== $employee->position) {
                        $fail('Position cannot be changed. It is tied to the login role.');
                    }
                },
            ],
        ], [
            'phone.regex' => self::PHONE_MESSAGE,
        ]);

        $data = $request->only([
            'first_name',
            'last_name',
            'phone',
            'block_lot',
            'street',
            'subdivision',
            'barangay',
            'city',
            'province',
            'postal_code',
            'position',
            'department',
            'daily_rate',
            'shift',
            'employment_type',
            'vehicle_plate',
        ]);

        // Rebuild the one-line address only when the separate fields were sent.
        $composed = AddressFormatter::compose($data + $employee->only(AddressFormatter::FIELDS));
        if ($request->hasAny(['street', 'barangay', 'subdivision', 'block_lot']) && $composed !== null) {
            $data['address'] = $composed;
        }

        $employee->update($data);

        return response()->json([
            'message' => 'Employee updated successfully!',
            'employee' => $employee
        ]);
    }

    // DELETE (soft delete — set is_active to false)
    // Deactivating an employee also disables the login account and signs it
    // out everywhere, so a former employee can no longer access the system.
    // Attendance, payroll and inventory history are kept.
    public function destroy($id)
    {
        $employee = Employee::with('user')->findOrFail($id);
        $employee->update(['is_active' => false]);

        if ($employee->user) {
            $employee->user->update(['is_active' => false]);
            // Revoke all Sanctum tokens so any open session stops working immediately.
            $employee->user->tokens()->delete();
        }

        return response()->json([
            'message' => 'Employee deactivated successfully! Their login account has been disabled.'
        ]);
    }
}