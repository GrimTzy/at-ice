<?php
namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index()
    {
        return response()->json(Supplier::all());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_code'  => 'required|unique:suppliers',
            'name'           => 'required|string',
            'contact_person' => 'nullable|string',
            'contact_number' => 'nullable|string',
            'email'          => 'nullable|email',
            'address'        => 'nullable|string',
            'city'           => 'nullable|string',
            'province'       => 'nullable|string',
            'notes'          => 'nullable|string',
        ]);

        $supplier = Supplier::create($data);
        return response()->json($supplier, 201);
    }

    public function show($id)
    {
        return response()->json(Supplier::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update($request->all());
        return response()->json($supplier);
    }

    public function destroy($id)
    {
        Supplier::findOrFail($id)->delete();
        return response()->json(['message' => 'Supplier deleted']);
    }
}  