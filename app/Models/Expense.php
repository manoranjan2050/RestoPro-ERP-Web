<?php

namespace App\Models;

use App\Models\Concerns\StoresPlainDate;
use App\Services\VendorLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class Expense extends Model
{
    use HasFactory, StoresPlainDate;

    public const MODES = ['cash' => 'Cash', 'upi' => 'UPI', 'bank' => 'Bank', 'card' => 'Card'];

    public const BILL_DISK = 'local'; // private: served only to signed-in users

    protected $fillable = [
        'date', 'category_id', 'vendor_id', 'amount', 'direct_paid', 'payment_mode', 'paid_on', 'note', 'bill_path',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'paid_on' => 'date',
            'amount' => 'decimal:2',
            'direct_paid' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    /**
     * direct_paid  = paid on the bill itself (at purchase or via "mark paid") — the only paid figure you set.
     * paid_amount  = direct_paid + khata payments allocated FIFO (maintained by VendorLedger::rebalance).
     * status       = derived from paid_amount.
     */
    protected static function booted(): void
    {
        static::saving(function (self $e) {
            $amount = round((float) $e->amount, 2);
            $direct = min(max(round((float) $e->direct_paid, 2), 0), $amount);
            $e->direct_paid = $direct;
            if ($direct <= 0) {
                $e->paid_on = null;
                $e->payment_mode = null;
            }
            // Provisional; vendor bills are corrected by rebalance() in `saved`.
            $paid = $e->vendor_id ? max($direct, min((float) $e->paid_amount, $amount)) : $direct;
            $e->paid_amount = $paid;
            $e->status = $paid >= $amount ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
        });

        static::saved(function (self $e) {
            VendorLedger::rebalance($e->vendor_id);
            if ($e->wasChanged('vendor_id') && $e->getOriginal('vendor_id')) {
                VendorLedger::rebalance((int) $e->getOriginal('vendor_id'));
            }
        });

        static::deleted(function (self $e) {
            VendorLedger::rebalance($e->vendor_id);
            if ($e->bill_path) {
                Storage::disk(self::BILL_DISK)->delete($e->bill_path);
            }
        });
    }

    protected function plainDateColumns(): array
    {
        return ['date', 'paid_on'];
    }

    /** Khata payments allocated to this bill. */
    public function khataPaid(): float
    {
        return round((float) $this->paid_amount - (float) $this->direct_paid, 2);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function scopeBetween(Builder $q, Carbon|string $from, Carbon|string $to): Builder
    {
        return $q->whereBetween('date', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()]);
    }

    public function balance(): float
    {
        return round((float) $this->amount - (float) $this->paid_amount, 2);
    }

    /** Totals for a query: amount, paid, unpaid, count. */
    public static function totals(Builder $q): array
    {
        $r = (clone $q)->reorder()
            ->selectRaw('COALESCE(SUM(amount),0) amount, COALESCE(SUM(paid_amount),0) paid, COUNT(*) n')->first();

        return [
            'amount' => (float) $r->amount,
            'paid' => (float) $r->paid,
            'unpaid' => round((float) $r->amount - (float) $r->paid, 2),
            'count' => (int) $r->n,
        ];
    }

    /** Amount per category for a query, largest first: [[name, color, amount], ...]. */
    public static function byCategory(Builder $q): array
    {
        return (clone $q)->reorder()
            ->join('expense_categories as c', 'c.id', '=', 'expenses.category_id')
            ->groupBy('c.id', 'c.name', 'c.color')
            ->selectRaw('c.id, c.name, c.color, SUM(expenses.amount) total')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'color' => $r->color, 'amount' => (float) $r->total])
            ->all();
    }
}
