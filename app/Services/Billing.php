<?php

namespace App\Services;

use App\Models\Customer;
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
 * Saving, holding and cancelling bills. Prices are always re-read from the item list here (never trusted from the
 * screen), totals come from BillCalculator, and every change to payments re-syncs that day's Cash Collection.
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

    /**
     * Turn cart lines [item_id, variant_id, qty, note] into priced lines from the database.
     * @return list<array>
     */
    public static function price(array $cart): array
    {
        $items = Item::whereIn('id', array_column($cart, 'item_id'))->get()->keyBy('id');
        $variants = ItemVariant::whereIn('id', array_filter(array_column($cart, 'variant_id')))->get()->keyBy('id');
        $lines = [];
        foreach (array_values($cart) as $i => $c) {
            $item = $items[$c['item_id']] ?? null;
            $variant = ! empty($c['variant_id']) ? ($variants[$c['variant_id']] ?? null) : null;
            if (! $item || (! empty($c['variant_id']) && (! $variant || $variant->item_id !== $item->id))) {
                throw ValidationException::withMessages(['cart' => 'An item on this bill no longer exists. Remove it and add it again.']);
            }
            $qty = round((float) $c['qty'], 3);
            if ($qty <= 0 || $qty > 99999) {
                throw ValidationException::withMessages(['cart' => "Check the quantity of {$item->name}."]);
            }
            $lines[] = [
                'item_id' => $item->id, 'variant_id' => $variant?->id, 'name' => $item->name, 'variant_name' => $variant?->name,
                'unit' => $item->unit, 'qty' => $qty, 'rate' => (float) ($variant?->price ?? $item->price),
                'gst_rate' => (float) $item->gst_rate, 'hsn' => $item->hsn,
                'note' => isset($c['note']) && trim((string) $c['note']) !== '' ? mb_substr(trim($c['note']), 0, 120) : null, 'sort' => $i,
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
     * Save a bill. $opts: discount_value, discount_type, customer_name, customer_mobile, type, note, hold (bool).
     * $payments: list of [mode, amount, reference?]. A paid bill needs payments that add up to the total.
     */
    public static function save(array $cart, array $opts, array $payments, User $by, ?Order $existing = null): Order
    {
        if (! $cart) {
            throw ValidationException::withMessages(['cart' => 'Add at least one item.']);
        }
        if ($existing && ! in_array($existing->status, ['held', 'open'], true)) {
            throw ValidationException::withMessages(['cart' => 'This bill is already closed.']);
        }
        $hold = (bool) ($opts['hold'] ?? false);
        $type = array_key_exists($opts['type'] ?? 'counter', Order::TYPES) ? ($opts['type'] ?? 'counter') : 'counter';
        $dType = ($opts['discount_type'] ?? 'amt') === 'pct' ? 'pct' : 'amt';
        $dValue = max(0, round((float) ($opts['discount_value'] ?? 0), 2));

        $lines = self::price($cart);
        $t = self::totals($lines, $dValue, $dType);
        if ($t['subtotal'] > 0 && $t['discount'] / $t['subtotal'] * 100 > self::maxDiscountPct($by) + 0.001) {
            throw ValidationException::withMessages(['discount' => 'Discount is above the '.self::maxDiscountPct($by).'% you are allowed to give. Ask the owner.']);
        }

        $pays = [];
        if (! $hold) {
            foreach ($payments as $p) {
                $amt = round((float) ($p['amount'] ?? 0), 2);
                if ($amt > 0 && array_key_exists($p['mode'] ?? '', OrderPayment::MODES)) {
                    $pays[] = ['mode' => $p['mode'], 'amount' => $amt, 'reference' => isset($p['reference']) ? mb_substr(trim((string) $p['reference']), 0, 60) ?: null : null];
                }
            }
            $paid = round(array_sum(array_column($pays, 'amount')), 2);
            if (abs($paid - $t['total']) > 0.009) {
                throw ValidationException::withMessages(['payments' => 'Payments ('.money($paid).') must add up to the bill total ('.money($t['total']).').']);
            }
        }

        $mobile = User::normaliseMobile($opts['customer_mobile'] ?? null);
        $name = trim((string) ($opts['customer_name'] ?? '')) ?: null;
        if (($opts['customer_mobile'] ?? '') !== '' && $opts['customer_mobile'] !== null && ! preg_match('/^\d{10}$/', (string) $mobile)) {
            throw ValidationException::withMessages(['customer_mobile' => 'Enter a 10-digit mobile number.']);
        }

        return DB::transaction(function () use ($existing, $hold, $type, $dType, $dValue, $lines, $t, $pays, $mobile, $name, $opts, $by) {
            $customer = null;
            if ($mobile) {
                $customer = Customer::firstOrCreate(['mobile' => $mobile], ['name' => $name ?? 'Customer']);
                if ($name && in_array($customer->name, ['Customer', ''], true)) {
                    $customer->update(['name' => $name]);
                }
            }

            $order = $existing ?? new Order(['created_by' => $by->id]);
            $order->fill([
                'type' => $type, 'status' => $hold ? 'held' : 'completed',
                'customer_id' => $customer?->id, 'customer_name' => $name ?? $customer?->name, 'customer_mobile' => $mobile,
                'customer_gstin' => $customer?->gstin,
                'gst_enabled' => Sales::gst(), 'gst_inclusive' => Sales::gstInclusive(),
                'subtotal' => $t['subtotal'], 'discount_type' => $dType, 'discount_value' => $dValue, 'discount' => $t['discount'],
                'taxable' => $t['taxable'], 'cgst' => $t['cgst'], 'sgst' => $t['sgst'], 'round_off' => $t['round_off'],
                'total' => $t['total'], 'paid' => $hold ? 0 : $t['total'],
                'note' => isset($opts['note']) && trim((string) $opts['note']) !== '' ? mb_substr(trim($opts['note']), 0, 255) : null,
            ]);
            if (! $hold) {
                $fy = self::fyLabel();
                $seq = (int) Order::where('fy', $fy)->lockForUpdate()->max('seq') + 1;
                $order->fill([
                    'fy' => $fy, 'seq' => $seq, 'billed_at' => now(), 'share_token' => Str::random(32),
                    'number' => Setting::get('bill_prefix', '').$fy.'/'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                ]);
            }
            $order->save();

            $order->items()->delete();
            foreach ($t['lines'] as $l) {
                $order->items()->create(collect($l)->only([
                    'item_id', 'variant_id', 'name', 'variant_name', 'unit', 'qty', 'rate', 'gst_rate', 'hsn', 'amount', 'discount',
                    'taxable', 'tax', 'total', 'note', 'sort',
                ])->all());
            }
            foreach ($pays as $p) {
                $order->payments()->create($p + ['date' => today()->toDateString(), 'created_by' => $by->id]);
            }
            if ($pays) {
                self::syncDay(today());
            }

            return $order->fresh(['items', 'payments']);
        });
    }

    /** Cancel a billed order (owner only — checked by the caller). Its payments stop counting in that day's sales. */
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

            return $order;
        });
    }

    /** Recompute one day's POS share of Cash Collection from bill payments (cancelled bills excluded). */
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
