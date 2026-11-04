<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A bill / order. Money columns are frozen at billing time (see BillCalculator). */
class Order extends Model
{
    public const TYPES = ['counter' => 'Counter', 'takeaway' => 'Takeaway', 'dine_in' => 'Dine-in', 'delivery' => 'Delivery', 'advance' => 'Advance order'];
    public const STATUSES = ['held' => 'On hold', 'open' => 'Open', 'completed' => 'Paid', 'cancelled' => 'Cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'details' => 'array', 'gst_enabled' => 'boolean', 'gst_inclusive' => 'boolean', 'due_at' => 'datetime',
            'cancelled_at' => 'datetime', 'billed_at' => 'datetime',
            'subtotal' => 'decimal:2', 'discount_value' => 'decimal:2', 'discount' => 'decimal:2', 'taxable' => 'decimal:2',
            'cgst' => 'decimal:2', 'sgst' => 'decimal:2', 'round_off' => 'decimal:2', 'total' => 'decimal:2', 'paid' => 'decimal:2',
            'delivery_charge' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeBilled(Builder $q): Builder
    {
        return $q->whereNotNull('number');
    }

    public function balance(): float
    {
        return round((float) $this->total - (float) $this->paid, 2);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function title(): string
    {
        return $this->number ? 'Bill '.$this->number : (self::STATUSES[$this->status] ?? 'Order').' #'.$this->id;
    }
}
