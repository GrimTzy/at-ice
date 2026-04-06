<?php
namespace App\Http\Controllers;

use App\Models\Customer;
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

        $customer = Customer::create($request->all());
        return response()->json($customer, 201);
    }

    public function show($id) {
        return response()->json(Customer::findOrFail($id));
    }

    public function update(Request $request, $id) {
        $customer = Customer::findOrFail($id);
        $customer->update($request->all());
        return response()->json($customer);
    }

    public function destroy($id) {
        Customer::findOrFail($id)->delete();
        return response()->json(['message' => 'Customer deleted.']);
    }
}