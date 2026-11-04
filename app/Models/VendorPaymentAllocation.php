<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorPaymentAllocation extends Model
{
    public $timestamps = false;

    protected $fillable = ['vendor_payment_id', 'expense_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(VendorPayment::class, 'vendor_payment_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
