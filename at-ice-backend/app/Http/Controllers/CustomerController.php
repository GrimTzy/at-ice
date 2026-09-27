<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\User;
use App\Services\GeocodingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    public function index() {
        return response()->json(
            Customer::with('user:id,email')->where('is_active', true)->get()
        );
    }

    public function store(Request $request) {
        $request->validate([
            'name'          => 'required|string',
            'customer_code' => 'required|string|unique:customers',
            'mop'           => 'required|in:cash,gcash,check,bank_transfer',
        ]);

        $data = $request->all();

        if ($request->filled('address') || $request->filled('city')) {
            $coords = GeocodingService::geocode(
                $request->input('address', ''),
                $request->input('city', ''),
                $request->input('province', '')
            );
            if ($coords) {
                $data['latitude']  = $coords['latitude'];
                $data['longitude'] = $coords['longitude'];
            }
        }

        $customer = Customer::create($data);
        return response()->json($customer, 201);
    }

    public function show($id) {
        return response()->json(Customer::findOrFail($id));
    }

    public function update(Request $request, $id) {
        $customer = Customer::findOrFail($id);
        $data = $request->all();

        $addressChanged = $request->filled('address') && $request->input('address') !== $customer->address
            || $request->filled('city') && $request->input('city') !== $customer->city
            || $request->filled('province') && $request->input('province') !== $customer->province;

        if ($addressChanged) {
            $coords = GeocodingService::geocode(
                $request->input('address', $customer->address ?? ''),
                $request->input('city', $customer->city ?? ''),
                $request->input('province', $customer->province ?? '')
            );
            if ($coords) {
                $data['latitude']  = $coords['latitude'];
                $data['longitude'] = $coords['longitude'];
            }
        }

        $customer->update($data);
        return response()->json($customer);
    }

    public function destroy($id) {
        Customer::findOrFail($id)->delete();
        return response()->json(['message' => 'Customer deleted.']);
    }

    // Creates a Customer Portal login account for an existing Customer
    public function createAccount($id)
    {
        $customer = Customer::findOrFail($id);

        if ($customer->user_id) {
            return response()->json(['message' => 'This customer already has a login account.'], 422);
        }

        $baseSlug = Str::slug($customer->name . '-' . ($customer->branch_code ?: $customer->city));
        $loginEmail = $baseSlug . '@atice-portal.com';

        $suffix = 1;
        $candidate = $loginEmail;
        while (User::where('email', $candidate)->exists()) {
            $suffix++;
            $candidate = $baseSlug . '-' . $suffix . '@atice-portal.com';
        }
        $loginEmail = $candidate;

        $plainPassword = Str::random(10);

        $user = User::create([
            'name'     => $customer->name,
            'email'    => $loginEmail,
            'password' => Hash::make($plainPassword),
            'role'     => 'customer',
        ]);

        $customer->update(['user_id' => $user->id]);

        return response()->json([
            'message'  => 'Login account created.',
            'email'    => $loginEmail,
            'password' => $plainPassword,
        ], 201);
    }
}