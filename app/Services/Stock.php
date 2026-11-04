<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Sales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Simple stock. Items with sizes keep stock per size; others on the item. Every change is a StockMovement row and the
 * running total lives on items.stock_qty / item_variants.stock_qty. Selling below zero is allowed (the counter must
 * never be blocked) — the Stock page shows it in red so someone can correct the count.
 */
class Stock
{
    /** Add (+) or remove (−) stock and log it. Returns the new balance. */
    public static function move(Item $item, ?ItemVariant $variant, float $qty, string $reason, ?User $by = null,
        ?string $note = null, ?Order $order = null, ?Carbon $date = null): float
    {
        if (! array_key_exists($reason, StockMovement::REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Unknown stock reason.']);
        }
        if ($variant && $variant->item_id !== $item->id) {
            throw ValidationException::withMessages(['variant_id' => 'That size belongs to another item.']);
        }
        if (! $variant && $item->variants()->exists()) {
            throw ValidationException::withMessages(['variant_id' => "Choose the size of {$item->name}."]);
        }
        $qty = round($qty, 3);
        if ($qty == 0) {
            return (float) ($variant?->stock_qty ?? $item->stock_qty);
        }

        return DB::transaction(function () use ($item, $variant, $qty, $reason, $by, $note, $order, $date) {
            $row = $variant ? ItemVariant::lockForUpdate()->find($variant->id) : Item::lockForUpdate()->find($item->id);
            $balance = round((float) $row->stock_qty + $qty, 3);
            $row->update(['stock_qty' => $balance]);
            StockMovement::create([
                'item_id' => $item->id, 'variant_id' => $variant?->id, 'date' => ($date ?? today())->toDateString(),
                'qty' => $qty, 'reason' => $reason, 'order_id' => $order?->id, 'balance' => $balance,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 120) : null, 'created_by' => $by?->id,
            ]);

            return $balance;
        });
    }

    /** Physical count: set stock to exactly $count (logs the difference as "adjust"). */
    public static function setCount(Item $item, ?ItemVariant $variant, float $count, ?User $by = null, ?string $note = null): float
    {
        $current = (float) ($variant ? $variant->fresh()->stock_qty : $item->fresh()->stock_qty);

        return self::move($item, $variant, round($count - $current, 3), 'adjust', $by, $note ?: 'Counted '.rtrim(rtrim(number_format($count, 3), '0'), '.'));
    }

    /**
     * Bring an order's stock in line with its lines. Running and paid orders use stock; held and cancelled orders don't.
     * Idempotent: compares with what this order already took, so editing a table bill or cancelling it just fixes the gap.
     */
    public static function syncOrder(Order $order, ?User $by = null): void
    {
        if (! Sales::on('stock')) {
            return;
        }
        $order->loadMissing('items');
        $uses = in_array($order->status, ['open', 'completed'], true);
        $want = [];
        foreach ($order->items as $l) {
            if ($l->item_id) {
                $k = $l->item_id.':'.($l->variant_id ?? 0);
                $want[$k] = ($want[$k] ?? 0) + ($uses ? (float) $l->qty : 0);
            }
        }
        $took = StockMovement::where('order_id', $order->id)->where('reason', 'sale')
            ->groupBy('item_id', 'variant_id')->selectRaw('item_id, variant_id, -SUM(qty) as q')->get()
            ->mapWithKeys(fn ($r) => [$r->item_id.':'.($r->variant_id ?? 0) => (float) $r->q])->all();

        $keys = array_unique(array_merge(array_keys($want), array_keys($took)));
        $items = Item::whereIn('id', array_map(fn ($k) => (int) explode(':', $k)[0], $keys))->get()->keyBy('id');
        foreach ($keys as $k) {
            [$itemId, $variantId] = array_map('intval', explode(':', $k));
            $item = $items[$itemId] ?? null;
            $already = $took[$k] ?? 0;
            if (! $item || (! $item->track_stock && $already == 0)) {
                continue; // not tracked (and never was for this order)
            }
            $diff = round(($want[$k] ?? 0) - $already, 3);
            if ($diff != 0) {
                $variant = $variantId ? ItemVariant::find($variantId) : null;
                if ($variantId && ! $variant) {
                    continue; // size deleted meanwhile
                }
                self::move($item, $variant, -$diff, 'sale', $by, $diff < 0 ? 'Returned / removed from '.$order->ref() : null, $order);
            }
        }
    }

    /** Tracked stock rows (one per item, or per size) for the Stock page. */
    public static function rows(?string $search = null): array
    {
        $items = Item::with(['variants', 'category'])->where('track_stock', true)->search($search)->orderBy('name')->get();
        $rows = [];
        foreach ($items as $item) {
            $list = $item->variants->isNotEmpty() ? $item->variants : collect([null]);
            foreach ($list as $v) {
                $qty = (float) ($v?->stock_qty ?? $item->stock_qty);
                $rows[] = [
                    'key' => $item->id.'-'.($v?->id ?? 0), 'item' => $item, 'variant' => $v,
                    'label' => $item->name.($v ? ' · '.$v->name : ''), 'qty' => $qty, 'unit' => $item->unit,
                    'low' => $item->low_stock_at !== null && $qty <= (float) $item->low_stock_at,
                    'active' => $item->is_active && (! $v || $v->is_active),
                ];
            }
        }

        return $rows;
    }
}
