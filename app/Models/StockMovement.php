<?php

namespace App\Models;

use App\Models\Concerns\StoresPlainDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use StoresPlainDate;

    /** reason => [label, direction shown on the Stock page] */
    public const REASONS = [
        'made' => ['Made today', 'in'],
        'purchase' => ['Bought / received', 'in'],
        'opening' => ['Opening stock', 'in'],
        'sale' => ['Sold', 'out'],
        'wastage' => ['Wastage / expired', 'out'],
        'adjust' => ['Count adjusted', 'any'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'qty' => 'decimal:3', 'balance' => 'decimal:3'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
