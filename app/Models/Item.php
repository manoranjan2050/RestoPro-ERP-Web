<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Something the shop sells. With variants, each variant carries its own price (and stock); otherwise `price` is used. */
class Item extends Model
{
    public const UNITS = ['pcs' => 'Piece', 'kg' => 'Kg', 'g' => 'Gram', 'plate' => 'Plate', 'box' => 'Box', 'ltr' => 'Litre', 'cup' => 'Cup'];
    public const GST_RATES = [0, 5, 12, 18, 28];
    public const FOOD_TYPES = ['veg' => 'Veg', 'egg' => 'Contains egg', 'nonveg' => 'Non-veg'];

    protected $fillable = [
        'category_id', 'name', 'code', 'barcode', 'unit', 'price', 'gst_rate', 'hsn', 'food_type', 'photo_path',
        'description', 'track_stock', 'stock_qty', 'low_stock_at', 'sort', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2', 'gst_rate' => 'decimal:2', 'stock_qty' => 'decimal:3', 'low_stock_at' => 'decimal:3',
            'track_stock' => 'boolean', 'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ItemVariant::class)->orderBy('sort')->orderBy('id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('code', $term)->orWhere('barcode', $term)
            ->orWhereHas('variants', fn ($v) => $v->where('barcode', $term)));
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('media', $this->photo_path) : null;
    }

    /** "₹120" or "₹450 – ₹1,600" across active variants. */
    public function priceLabel(): string
    {
        $prices = $this->relationLoaded('variants') ? $this->variants->where('is_active', true)->pluck('price') : collect();
        if ($prices->isEmpty()) {
            return money($this->price);
        }
        [$min, $max] = [$prices->min(), $prices->max()];

        return $min == $max ? money($min) : money($min).' – '.money($max);
    }

    /** Total stock on hand (variants add up). */
    public function stockOnHand(): float
    {
        return $this->relationLoaded('variants') && $this->variants->isNotEmpty()
            ? (float) $this->variants->sum('stock_qty')
            : (float) $this->stock_qty;
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && $this->low_stock_at !== null && $this->stockOnHand() <= (float) $this->low_stock_at;
    }
}
