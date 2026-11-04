<?php

namespace App\Models;

use App\Models\Concerns\StoresPlainDate;
use App\Services\VendorLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorPayment extends Model
{
    use StoresPlainDate;

    protected $fillable = ['vendor_id', 'date', 'amount', 'payment_mode', 'note', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $p) => VendorLedger::rebalance($p->vendor_id));
        static::deleted(fn (self $p) => VendorLedger::rebalance($p->vendor_id));
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class);
    }
}
