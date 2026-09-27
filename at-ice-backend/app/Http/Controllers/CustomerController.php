<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\GeocodingService;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index() {
        return response()->json(Customer::where('is_active', true)->get());
    }

    public function store(Request $request) {
        $request->validate([
            'name'          => 'required|string',
            'customer_code' => 'required|string|unique:customers',
            'mop'           => 'required|in:cash,gcash,check,bank_transfer',
        ]);

        $data = $request->all();

        // Geocode the address into lat/lng if address fields were provided.
        // Silently leaves lat/lng null on failure — a missing pin later is
        // recoverable (re-save the customer), a blocked customer creation
        // over a geocoding hiccup is not.
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

        // Re-geocode only when the address actually changed, so we don't
        // hammer Nominatim on every unrelated field edit (e.g. just
        // updating contact_number).
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
}