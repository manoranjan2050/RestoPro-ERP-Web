<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A size/weight of an item with its own price and stock: Black Forest → 500 g / 1 kg / 2 kg. */
class ItemVariant extends Model
{
    protected $fillable = ['item_id', 'name', 'price', 'barcode', 'stock_qty', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'stock_qty' => 'decimal:3', 'is_active' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
