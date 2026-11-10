<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One delivery schedule per customer (a whole chain such as SM, or one store):
// a date range and the days of the week it is delivered. Admin approves it,
// and App\Support\DeliveryDispatch::generate makes the daily deliveries.
class DeliveryContract extends Model
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected $fillable = [
        'contract_no', 'name', 'chain', 'start_date', 'end_date', 'delivery_days',
        'delivery_time', 'items', 'status', 'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'delivery_days' => 'array',
        'items' => 'array',
        'approved_at' => 'datetime',
    ];

    // Contract ID: DC-{customer code}-{YYMM}-{no.}, e.g. DC-TIN-2611-001.
    // DC is the document type (Delivery Contract), the customer code is the
    // one on its invoices (customers.invoice_code), YYMM is the month it was
    // made and the number counts that customer's contracts in that month.
    // Older contracts keep their C-0001 numbers. Call it inside the
    // transaction that saves the contract; contract_no is unique.
    public static function nextNumber(?string $chain, array $storeIds, ?\DateTimeInterface $on = null): string
    {
        $code = self::codeFor($chain, $storeIds);
        $prefix = 'DC-'.$code.'-'.($on ?? now(config('atice.timezone')))->format('ym').'-';
        $last = self::where('contract_no', 'like', $prefix.'%')->pluck('contract_no')
            ->map(fn ($n) => (int) substr($n, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
    }

    // The chain's initials for a chain contract; otherwise the stores' own
    // invoice code (all the same company), or MULTI for stores of different
    // companies.
    private static function codeFor(?string $chain, array $storeIds): string
    {
        $free = fn () => false; // only the letters are wanted, not a free invoice code
        if ($chain) {
            return Customer::invoiceCodeFor($chain, $free);
        }
        $codes = Customer::whereIn('id', $storeIds)->get(['name', 'invoice_code'])
            ->map(fn ($c) => $c->invoice_code ?: Customer::invoiceCodeFor($c->name, $free))
            ->unique()->values();

        return $codes->count() === 1 ? $codes->first() : 'MULTI';
    }

    public function stores()
    {
        return $this->belongsToMany(Customer::class, 'delivery_contract_stores', 'delivery_contract_id', 'customer_id');
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class, 'contract_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
