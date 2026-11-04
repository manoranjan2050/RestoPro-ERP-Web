<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Setting;
use App\Models\User;
use App\Support\Sales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Saving, holding, running and cancelling orders. Prices are always re-read from the item list here (never trusted
 * from the screen), totals come from BillCalculator, and every change to payments re-syncs that day's Cash Collection.
 *
 * Modes:  hold     — parked on the POS, no number, no money
 *         open     — running order: dine-in table, delivery, advance booking. May take part payments (advance).
 *         complete — fully paid; gets its bill number (once) and leaves the counter.
 * `status` is the money side (held/open/completed/cancelled); `stage` is the kitchen/delivery side.
 */
class Billing
{
    /** Financial-year label for bill numbers: "2026-27" (or "2026" when the year starts in January). */
    public static function fyLabel(?Carbon $date = null): string
    {
        $start = RevenueReport::fyStart($date ?? now(), (int) Setting::get('fy_start_month', '4'));

        return $start->month === 1 ? (string) $start->year : $start->year.'-'.substr((string) ($start->year + 1), -2);
    }

    public static function roundOff(): bool
    {
        return Setting::get('pos_round_off', '1') === '1';
    }

    /** Biggest discount (%) a manager/cashier may give. Owners are never limited. */
    public static function maxDiscountPct(User $user): float
    {
        return $user->isOwner() ? 100 : (float) Setting::get('pos_max_discount', '100');
    }

    public static function defaultDeliveryCharge(): float
    {
        return (float) Setting::get('delivery_charge', '0');
    }

    /**
     * Turn cart lines [item_id, variant_id, qty, note, kot_no?] into priced lines from the database.
     * @return list<array>
     */
    public static function price(array $cart): array
    {
        $items = Item::withCount(['variants' => fn ($q) => $q->where('is_active', true)])->whereIn('id', array_column($cart, 'item_id'))->get()->keyBy('id');
        $variants = ItemVariant::whereIn('id', array_filter(array_column($cart, 'variant_id')))->get()->keyBy('id');
        $lines = [];
        foreach (array_values($cart) as $i => $c) {
            // Custom line: something not on the menu (a made-to-order cake). Name and price come from the counter.
            if (empty($c['item_id']) && ! empty($c['custom'])) {
                $x = $c['custom'];
                $name = mb_substr(trim((string) ($x['name'] ?? '')), 0, 100);
                $rate = round((float) ($x['rate'] ?? 0), 2);
                $gst = (float) ($x['gst_rate'] ?? 0);
                $qty = round((float) ($c['qty'] ?? 1), 3);
                if ($name === '') {
                    throw ValidationException::withMessages(['cart' => 'Give the custom item a name.']);
                }
                if ($rate < 0 || $rate > 9999999) {
                    throw ValidationException::withMessages(['cart' => "Check the price of {$name}."]);
                }
                if (! in_array($gst, array_map('floatval', Item::GST_RATES), true)) {
                    throw ValidationException::withMessages(['cart' => "GST for {$name} must be 0, 5, 12, 18 or 28%."]);
                }
                if ($qty <= 0 || $qty > 99999) {
                    throw ValidationException::withMessages(['cart' => "Check the quantity of {$name}."]);
                }
                $lines[] = [
                    'item_id' => null, 'variant_id' => null, 'name' => $name, 'variant_name' => null, 'unit' => 'pcs',
                    'qty' => $qty, 'rate' => $rate, 'gst_rate' => $gst, 'hsn' => trim((string) ($x['hsn'] ?? '')) ?: null,
                    'note' => isset($c['note']) && trim((string) $c['note']) !== '' ? mb_substr(trim($c['note']), 0, 120) : null,
                    'kot_no' => isset($c['kot_no']) && $c['kot_no'] ? (int) $c['kot_no'] : null, 'sort' => $i,
                ];

                continue;
            }
            $item = $items[$c['item_id']] ?? null;
            $variant = ! empty($c['variant_id']) ? ($variants[$c['variant_id']] ?? null) : null;
            if (! $item || (! empty($c['variant_id']) && (! $variant || $variant->item_id !== $item->id))) {
                throw ValidationException::withMessages(['cart' => 'An item on this bill no longer exists. Remove it and add it again.']);
            }
            if (! $variant && $item->variants_count > 0) {
                throw ValidationException::withMessages(['cart' => "Choose a size for {$item->name}."]);
            }
            $qty = round((float) $c['qty'], 3);
            if ($qty <= 0 || $qty > 99999) {
                throw ValidationException::withMessages(['cart' => "Check the quantity of {$item->name}."]);
            }
            $lines[] = [
                'item_id' => $item->id, 'variant_id' => $variant?->id, 'name' => $item->name, 'variant_name' => $variant?->name,
                'unit' => $item->unit, 'qty' => $qty, 'rate' => (float) ($variant?->price ?? $item->price),
                'gst_rate' => (float) $item->gst_rate, 'hsn' => $item->hsn,
                'note' => isset($c['note']) && trim((string) $c['note']) !== '' ? mb_substr(trim($c['note']), 0, 120) : null,
                'kot_no' => isset($c['kot_no']) && $c['kot_no'] ? (int) $c['kot_no'] : null, 'sort' => $i,
            ];
        }

        return $lines;
    }

    /** Live totals for the screen and for saving. */
    public static function totals(array $lines, float $discountValue, string $discountType, float $extra = 0, ?bool $gst = null, ?bool $inclusive = null): array
    {
        return BillCalculator::calc($lines, $discountValue, $discountType, $gst ?? Sales::gst(), $inclusive ?? Sales::gstInclusive(), self::roundOff(), $extra);
    }

    /**
     * Save an order. $opts: mode (complete|open|hold) or legacy hold=true, type, discount_value, discount_type,
     * customer_name, customer_mobile, note, table_id, guests, delivery_address, delivery_charge, due_at, details.
     * $payments: list of [mode, amount, reference?] taken now. On complete, earlier + new payments must equal the total.
     */
    public static function save(array $cart, array $opts, array $payments, User $by, ?Order $existing = null): Order
    {
        $mode = ! empty($opts['hold']) ? 'hold' : ($opts['mode'] ?? 'complete');
        if (! in_array($mode, ['hold', 'open', 'complete'], true)) {
            $mode = 'complete';
        }
        if (! $cart) {
            throw ValidationException::withMessages(['cart' => 'Add at least one item.']);
        }
        if ($existing && ! in_array($existing->status, ['held', 'open'], true)) {
            throw ValidationException::withMessages(['cart' => 'This bill is already closed.']);
        }
        $type = array_key_exists($opts['type'] ?? 'counter', Order::TYPES) ? ($opts['type'] ?? 'counter') : 'counter';
        $dType = ($opts['discount_type'] ?? 'amt') === 'pct' ? 'pct' : 'amt';
        $dValue = max(0, round((float) ($opts['discount_value'] ?? 0), 2));
        $prior = $existing ? round((float) $existing->payments()->sum('amount'), 2) : 0.0;
        if ($mode === 'hold' && $prior > 0) {
            throw ValidationException::withMessages(['cart' => 'This order already has a payment — it can\'t be put on hold.']);
        }

        // Customer
        $mobile = User::normaliseMobile($opts['customer_mobile'] ?? null);
        $name = trim((string) ($opts['customer_name'] ?? '')) ?: null;
        if (trim((string) ($opts['customer_mobile'] ?? '')) !== '' && ! preg_match('/^\d{10}$/', (string) $mobile)) {
            throw ValidationException::withMessages(['customer_mobile' => 'Enter a 10-digit mobile number.']);
        }

        // Type-specific fields
        $extra = ['table_id' => null, 'guests' => null, 'delivery_address' => null, 'delivery_charge' => 0, 'due_at' => null, 'details' => null];
        if ($type === 'dine_in') {
            $table = DiningTable::where('is_active', true)->find($opts['table_id'] ?? 0);
            if (! $table) {
                throw ValidationException::withMessages(['table_id' => 'Choose a table.']);
            }
            $busy = Order::where('status', 'open')->where('type', 'dine_in')->where('table_id', $table->id)
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists();
            if ($busy && $mode !== 'hold') {
                throw ValidationException::withMessages(['table_id' => "{$table->name} already has a running bill."]);
            }
            $extra['table_id'] = $table->id;
            $extra['guests'] = ($g = (int) ($opts['guests'] ?? 0)) > 0 ? min($g, 99) : null;
        }
        if ($type === 'delivery') {
            $extra['delivery_address'] = trim((string) ($opts['delivery_address'] ?? '')) ?: null;
            $extra['delivery_charge'] = max(0, round((float) ($opts['delivery_charge'] ?? 0), 2));
            if ($mode !== 'hold' && (! $mobile || ! $extra['delivery_address'])) {
                throw ValidationException::withMessages(['delivery_address' => 'Delivery needs the customer\'s mobile and address.']);
            }
            $extra['delivery_address'] = $extra['delivery_address'] ? mb_substr($extra['delivery_address'], 0, 255) : null;
        }
        if ($type === 'advance') {
            try {
                $extra['due_at'] = ! empty($opts['due_at']) ? Carbon::parse($opts['due_at']) : null;
            } catch (\Throwable) {
                $extra['due_at'] = null;
            }
            if ($mode !== 'hold' && (! $extra['due_at'] || ! $mobile || ! $name)) {
                throw ValidationException::withMessages(['due_at' => 'An advance order needs the pickup date & time, and the customer\'s name and mobile.']);
            }
            $d = collect($opts['details'] ?? [])->only(array_keys(Order::CAKE_FIELDS))
                ->map(fn ($v, $k) => $k === 'eggless' ? (bool) $v : mb_substr(trim((string) $v), 0, 200))->filter(fn ($v) => $v !== '' && $v !== false);
            $extra['details'] = $d->isNotEmpty() ? $d->all() : null;
        }

        $lines = self::price($cart);
        $t = self::totals($lines, $dValue, $dType, (float) $extra['delivery_charge']);
        if ($t['subtotal'] > 0 && $t['discount'] / $t['subtotal'] * 100 > self::maxDiscountPct($by) + 0.001) {
            throw ValidationException::withMessages(['discount' => 'Discount is above the '.self::maxDiscountPct($by).'% you are allowed to give. Ask the owner.']);
        }

        $pays = [];
        if ($mode !== 'hold') {
            foreach ($payments as $p) {
                $amt = round((float) ($p['amount'] ?? 0), 2);
                if ($amt > 0 && array_key_exists($p['mode'] ?? '', OrderPayment::MODES)) {
                    $pays[] = ['mode' => $p['mode'], 'amount' => $amt, 'reference' => isset($p['reference']) ? (mb_substr(trim((string) $p['reference']), 0, 60) ?: null) : null];
                }
            }
            $paid = round($prior + array_sum(array_column($pays, 'amount')), 2);
            if ($mode === 'complete' && abs($paid - $t['total']) > 0.009) {
                throw ValidationException::withMessages(['payments' => 'Payments ('.money($paid).') must add up to the bill total ('.money($t['total']).').']);
            }
            if ($mode === 'open' && $paid - $t['total'] > 0.009) {
                throw ValidationException::withMessages(['payments' => 'Advance ('.money($paid).') is more than the order total ('.money($t['total']).').']);
            }
            if ($prior - $t['total'] > 0.009) {
                throw ValidationException::withMessages(['cart' => 'The order is now less than what was already paid ('.money($prior).').']);
            }
        }

        return DB::transaction(function () use ($existing, $mode, $type, $dType, $dValue, $lines, $t, $pays, $prior, $mobile, $name, $opts, $by, $extra) {
            $customer = null;
            if ($mobile) {
                $customer = Customer::firstOrCreate(['mobile' => $mobile], ['name' => $name ?? 'Customer']);
                if ($name && in_array($customer->name, ['Customer', ''], true)) {
                    $customer->update(['name' => $name]);
                }
                if ($type === 'delivery' && $extra['delivery_address'] && ! $customer->address) {
                    $customer->update(['address' => $extra['delivery_address']]);
                }
            }

            $order = $existing ?? new Order(['created_by' => $by->id]);
            $stages = array_keys(Order::STAGES[$type] ?? []);
            $order->fill($extra + [
                'type' => $type,
                'status' => ['hold' => 'held', 'open' => 'open', 'complete' => 'completed'][$mode],
                'stage' => $stages ? (in_array($order->stage, $stages, true) ? $order->stage : $stages[0]) : null,
                'customer_id' => $customer?->id, 'customer_name' => $name ?? $customer?->name, 'customer_mobile' => $mobile,
                'customer_gstin' => $customer?->gstin,
                'gst_enabled' => Sales::gst(), 'gst_inclusive' => Sales::gstInclusive(),
                'subtotal' => $t['subtotal'], 'discount_type' => $dType, 'discount_value' => $dValue, 'discount' => $t['discount'],
                'taxable' => $t['taxable'], 'cgst' => $t['cgst'], 'sgst' => $t['sgst'], 'round_off' => $t['round_off'],
                'total' => $t['total'], 'paid' => round($prior + array_sum(array_column($pays, 'amount')), 2),
                'note' => isset($opts['note']) && trim((string) $opts['note']) !== '' ? mb_substr(trim($opts['note']), 0, 255) : null,
            ]);
            if ($mode !== 'hold' && ! $order->share_token) {
                $order->share_token = Str::random(32);
            }
            if ($mode === 'complete' && ! $order->number) {
                $fy = self::fyLabel();
                $seq = (int) Order::where('fy', $fy)->lockForUpdate()->max('seq') + 1;
                $order->fill([
                    'fy' => $fy, 'seq' => $seq, 'billed_at' => now(),
                    'number' => Setting::get('bill_prefix', '').$fy.'/'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                ]);
            }
            $order->save();

            $order->items()->delete();
            foreach ($t['lines'] as $l) {
                $order->items()->create(collect($l)->only([
                    'item_id', 'variant_id', 'name', 'variant_name', 'unit', 'qty', 'rate', 'gst_rate', 'hsn', 'amount', 'discount',
                    'taxable', 'tax', 'total', 'note', 'kot_no', 'sort',
                ])->all());
            }
            foreach ($pays as $p) {
                $order->payments()->create($p + ['date' => today()->toDateString(), 'created_by' => $by->id]);
            }
            if ($pays) {
                self::syncDay(today());
            }
            Stock::syncOrder($order->fresh('items'), $by);

            return $order->fresh(['items', 'payments']);
        });
    }

    /** Give the next KOT number to every line not yet sent to the kitchen. Returns that number, or null if none. */
    public static function sendKot(Order $order): ?int
    {
        return DB::transaction(function () use ($order) {
            $new = $order->items()->whereNull('kot_no')->lockForUpdate()->get();
            if ($new->isEmpty()) {
                return null;
            }
            $no = (int) $order->items()->max('kot_no') + 1;
            $order->items()->whereIn('id', $new->pluck('id'))->update(['kot_no' => $no]);

            return $no;
        });
    }

    public static function setStage(Order $order, string $stage): Order
    {
        if (! array_key_exists($stage, Order::STAGES[$order->type] ?? []) || $order->isCancelled()) {
            throw ValidationException::withMessages(['stage' => 'That step does not apply to this order.']);
        }
        $order->update(['stage' => $stage]);

        return $order;
    }

    /** Move a running dine-in bill to another free table. */
    public static function moveTable(Order $order, int $tableId): Order
    {
        $table = DiningTable::where('is_active', true)->findOrFail($tableId);
        if (Order::where('status', 'open')->where('type', 'dine_in')->where('table_id', $table->id)->whereKeyNot($order->id)->exists()) {
            throw ValidationException::withMessages(['table_id' => "{$table->name} is not free."]);
        }
        $order->update(['table_id' => $table->id]);

        return $order;
    }

    /** Cancel an order (owner only — checked by the caller). Its payments stop counting in the day's sales. */
    public static function cancel(Order $order, string $reason, User $by): Order
    {
        if ($order->isCancelled()) {
            return $order;
        }
        if ($order->status === 'held') {
            $order->delete(); // a held bill was never billed — just discard it

            return $order;
        }

        return DB::transaction(function () use ($order, $reason, $by) {
            $order->update(['status' => 'cancelled', 'cancelled_by' => $by->id, 'cancelled_at' => now(), 'cancel_reason' => mb_substr(trim($reason), 0, 255)]);
            foreach ($order->payments()->pluck('date')->unique() as $d) {
                self::syncDay($d);
            }
            Stock::syncOrder($order->fresh('items'), $by); // put the stock back

            return $order;
        });
    }

    /** Recompute one day's POS share of Cash Collection from order payments (cancelled orders excluded). */
    public static function syncDay(Carbon|string $date): void
    {
        $date = Carbon::parse($date)->toDateString();
        $sums = ['cash' => 0.0, 'upi' => 0.0, 'online' => 0.0];
        $rows = OrderPayment::query()->join('orders', 'orders.id', '=', 'order_payments.order_id')
            ->where('orders.status', '!=', 'cancelled')->whereDate('order_payments.date', $date)
            ->groupBy('order_payments.mode')->selectRaw('order_payments.mode, SUM(order_payments.amount) as s')->pluck('s', 'mode');
        foreach ($rows as $mode => $s) {
            $sums[OrderPayment::MODES[$mode][1] ?? 'online'] += (float) $s;
        }
        DaySales::setPos($date, $sums);
    }
}
