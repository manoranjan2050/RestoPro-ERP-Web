<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CashCollection extends Model
{
    use HasFactory, \App\Models\Concerns\StoresPlainDate;

    protected $fillable = ['date', 'cash', 'upi', 'online', 'note', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cash' => 'decimal:2',
            'upi' => 'decimal:2',
            'online' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $c) {
            $c->total = round((float) $c->cash + (float) $c->upi + (float) $c->online, 2);
        });
    }

    public function scopeBetween(Builder $q, Carbon|string $from, Carbon|string $to): Builder
    {
        return $q->whereBetween('date', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()]);
    }

    /** Sums of each payment mode for a query. */
    public static function totals(Builder $q): array
    {
        $r = (clone $q)->selectRaw('COALESCE(SUM(cash),0) cash, COALESCE(SUM(upi),0) upi, COALESCE(SUM(online),0) online, COALESCE(SUM(total),0) total, COUNT(*) days')
            ->reorder()->first();

        return [
            'cash' => (float) $r->cash,
            'upi' => (float) $r->upi,
            'online' => (float) $r->online,
            'total' => (float) $r->total,
            'days' => (int) $r->days,
        ];
    }
}
