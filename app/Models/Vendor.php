<?php

namespace App\Models;

use App\Services\VendorLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'mobile', 'address', 'default_category_id', 'opening_balance', 'note', 'is_active'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(function (self $v) {
            if ($v->wasChanged('opening_balance')) {
                VendorLedger::rebalance($v->id);
            }
        });
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }

    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'default_category_id');
    }

    /** Adds aggregates for due() without N+1: bills_net, total_bills, payments_total. */
    public function scopeWithDue(Builder $q): Builder
    {
        return $q->addSelect([
            'bills_net' => Expense::selectRaw('COALESCE(SUM(amount - direct_paid), 0)')->whereColumn('expenses.vendor_id', 'vendors.id'),
            'total_bills' => Expense::selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('expenses.vendor_id', 'vendors.id'),
            'payments_total' => VendorPayment::selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('vendor_payments.vendor_id', 'vendors.id'),
        ]);
    }

    /** Balance owed to this vendor; negative means you've paid an advance. */
    public function due(): float
    {
        if (array_key_exists('bills_net', $this->attributes)) {
            return round((float) $this->opening_balance + (float) $this->bills_net - (float) $this->payments_total, 2);
        }

        return VendorLedger::balance($this);
    }
}
