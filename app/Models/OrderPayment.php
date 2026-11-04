<?php

namespace App\Models;

use App\Models\Concerns\StoresPlainDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPayment extends Model
{
    use StoresPlainDate;

    /** POS payment modes → [label, the Cash Collection column they count under]. */
    public const MODES = ['cash' => ['Cash', 'cash'], 'upi' => ['UPI', 'upi'], 'card' => ['Card', 'online'], 'online' => ['Online', 'online']];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
