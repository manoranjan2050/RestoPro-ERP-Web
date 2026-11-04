<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3', 'rate' => 'decimal:2', 'gst_rate' => 'decimal:2', 'amount' => 'decimal:2',
            'discount' => 'decimal:2', 'taxable' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function label(): string
    {
        return $this->name.($this->variant_name ? ' ('.$this->variant_name.')' : '');
    }

    public function qtyLabel(): string
    {
        return rtrim(rtrim(number_format((float) $this->qty, 3), '0'), '.');
    }
}
