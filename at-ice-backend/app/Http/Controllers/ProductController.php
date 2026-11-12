<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\TextRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    // Inventory = supplies and materials only (plastic bags, sacks, truck
    // parts, machine components). Ice is not inventoried.
    //
    // Stock levels are NEVER set here. current_stock only changes through
    // Stock In / Stock Out movements that a second Checker confirms
    // (double-entry, see StockMovementController). New items start at 0.

    public function index()
    {
        $products = Product::orderBy('name')->get();

        return response()->json($products);
    }

    // Next free code in the format SUP-0001, SUP-0002, ...
    private function nextProductCode(): string
    {
        $n = (Product::max('id') ?? 0) + 1;
        do {
            $code = 'SUP-'.str_pad($n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (Product::where('product_code', $code)->exists());

        return $code;
    }

    // Minimum and maximum stock are set by Admin only (owner, 2026-10-16).
    // Everyone else sees them read-only: a Checker's new item gets the
    // defaults, and an edit may only send the values unchanged.
    private function stockLimitsLocked(Request $request, ?Product $product = null)
    {
        if ($request->user()?->role === 'admin') {
            return null;
        }

        foreach (['minimum_stock', 'maximum_stock'] as $field) {
            if (! $request->has($field)) {
                continue;
            }
            $sent = $request->input($field);
            $current = $product?->{$field};
            $changed = $product
                ? ($sent === null || $sent === '' ? $current !== null : (string) (int) $sent !== (string) $current)
                : ($sent !== null && $sent !== '');
            if ($changed) {
                return response()->json([
                    'message' => 'Only the Admin can set the minimum and maximum stock.',
                    'errors' => [$field => ['Only the Admin can set the minimum and maximum stock.']],
                ], 403);
            }
        }

        return null;
    }

    // The digit caps bind the Admin, who sets the limits. A Checker's form sends the
    // saved limits back unchanged and they are ignored, so an old item over a cap
    // must not block the Checker's edit.
    private function limitRule(Request $request, int $cap): string
    {
        return 'nullable|integer|min:0'.($request->user()?->role === 'admin' ? "|max:{$cap}" : '');
    }

    public function store(Request $request)
    {
        if ($locked = $this->stockLimitsLocked($request)) {
            return $locked;
        }

        $validated = $request->validate([
            'product_code' => 'nullable|string|max:50|unique:products,product_code',
            'name' => ['required', ...TextRules::name(TextRules::NAME, 'Item name'), 'unique:products,name'],
            'category' => 'nullable|string|max:'.TextRules::NAME, // picked from a list; old codes like spare_parts stay valid
            'unit' => 'nullable|string|max:50',
            'unit_price' => 'nullable|numeric|min:0',
            'minimum_stock' => $this->limitRule($request, Product::MIN_LIMIT_CAP),
            'maximum_stock' => $this->limitRule($request, Product::MAX_LIMIT_CAP),
            'description' => ['nullable', ...TextRules::note()],
        ], [
            'name.unique' => 'An item with this name already exists.',
            'minimum_stock.max' => 'Minimum stock can have at most 2 digits (up to '.Product::MIN_LIMIT_CAP.').',
            'maximum_stock.max' => 'Maximum stock can have at most 5 digits (up to '.number_format(Product::MAX_LIMIT_CAP).').',
        ]);

        if (isset($validated['minimum_stock'], $validated['maximum_stock'])
            && (int) $validated['maximum_stock'] < (int) $validated['minimum_stock']) {
            return response()->json([
                'message' => 'Maximum stock must be greater than or equal to the minimum stock.',
                'errors' => ['maximum_stock' => ['Maximum stock must be greater than or equal to the minimum stock.']],
            ], 422);
        }

        if (empty($validated['product_code'])) {
            $validated['product_code'] = $this->nextProductCode();
        }

        // Blank limits (and every limit sent by a non-Admin) use the defaults and
        // leave the item marked as still needing its limits.
        foreach (['minimum_stock', 'maximum_stock'] as $field) {
            if (($validated[$field] ?? null) === null || $request->user()?->role !== 'admin') {
                unset($validated[$field]);
            }
        }
        $validated += ['minimum_stock' => Product::DEFAULT_MIN, 'maximum_stock' => Product::DEFAULT_MAX];
        if ($request->user()?->role === 'admin' && $request->filled('minimum_stock') && $request->filled('maximum_stock')) {
            $validated['limits_set_at'] = now();
        }

        // Stock always starts at zero; it is added through a confirmed Stock In.
        $validated['current_stock'] = 0;

        $product = Product::create($validated);

        return response()->json($product, 201);
    }

    public function show($id)
    {
        $product = Product::with(['stockMovements.enteredBy', 'stockMovements.confirmedBy'])->findOrFail($id);

        return response()->json($product);
    }

    // Edits item details only. current_stock and product_code are not
    // editable here, so stock cannot be changed without double-entry.
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        if ($locked = $this->stockLimitsLocked($request, $product)) {
            return $locked;
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', ...TextRules::name(TextRules::NAME, 'Item name'), Rule::unique('products', 'name')->ignore($product->id)],
            'category' => 'nullable|string|max:'.TextRules::NAME, // picked from a list; old codes like spare_parts stay valid
            'unit' => 'nullable|string|max:50',
            'unit_price' => 'nullable|numeric|min:0',
            'minimum_stock' => $this->limitRule($request, Product::MIN_LIMIT_CAP),
            'maximum_stock' => $this->limitRule($request, Product::MAX_LIMIT_CAP),
            'description' => ['nullable', ...TextRules::note()],
        ], [
            'name.unique' => 'An item with this name already exists.',
            'minimum_stock.max' => 'Minimum stock can have at most 2 digits (up to '.Product::MIN_LIMIT_CAP.').',
            'maximum_stock.max' => 'Maximum stock can have at most 5 digits (up to '.number_format(Product::MAX_LIMIT_CAP).').',
        ]);

        foreach (['minimum_stock', 'maximum_stock'] as $field) {
            if (array_key_exists($field, $validated) && ($validated[$field] === null || $request->user()?->role !== 'admin')) {
                unset($validated[$field]);
            }
        }

        $min = array_key_exists('minimum_stock', $validated) ? $validated['minimum_stock'] : $product->minimum_stock;
        $max = array_key_exists('maximum_stock', $validated) ? $validated['maximum_stock'] : $product->maximum_stock;
        if ($min !== null && $max !== null && (int) $max < (int) $min) {
            return response()->json([
                'message' => 'Maximum stock must be greater than or equal to the minimum stock.',
                'errors' => ['maximum_stock' => ['Maximum stock must be greater than or equal to the minimum stock.']],
            ], 422);
        }

        // the Admin saving the limits is what clears "Needs limits"
        if (array_key_exists('minimum_stock', $validated) || array_key_exists('maximum_stock', $validated)) {
            $validated['limits_set_at'] = now();
        }

        $product->update($validated);

        return response()->json($product->fresh());
    }

    // An item that already has stock movements cannot be deleted, so the
    // inventory history stays complete.
    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        if ($product->stockMovements()->exists()) {
            return response()->json([
                'message' => 'This item already has stock movements and cannot be deleted. Its history must be kept.',
            ], 422);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }
}
