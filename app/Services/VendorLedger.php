<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Vendor;
use App\Models\VendorPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Vendor khata (udhaar ledger).
 *
 * Balance  = opening_balance + Σ(bill.amount − bill.direct_paid) − Σ khata payments   (negative = advance paid)
 * Bill status comes from spreading khata payments FIFO: opening balance first, then bills oldest → newest.
 * rebalance() rebuilds that spread from scratch, so add/edit/delete of any bill or payment stays consistent.
 * All arithmetic is done in paise (integers) to avoid float drift.
 */
class VendorLedger
{
    public static function rebalance(?int $vendorId): void
    {
        if (! $vendorId || ! ($vendor = Vendor::find($vendorId))) {
            return;
        }

        DB::transaction(function () use ($vendor) {
            $payments = VendorPayment::where('vendor_id', $vendor->id)->orderBy('date')->orderBy('id')->get(['id', 'amount']);
            DB::table('vendor_payment_allocations')->whereIn('vendor_payment_id', $payments->pluck('id'))->delete();

            $bills = Expense::where('vendor_id', $vendor->id)->orderBy('date')->orderBy('id')
                ->get(['id', 'amount', 'direct_paid', 'paid_amount', 'status']);

            // Outstanding queue: opening balance, then each bill's unpaid part.
            $queue = [['expense_id' => null, 'left' => self::paise($vendor->opening_balance)]];
            foreach ($bills as $b) {
                $queue[] = ['expense_id' => $b->id, 'left' => max(0, self::paise($b->amount) - self::paise($b->direct_paid))];
            }

            $allocated = [];
            $rows = [];
            $i = 0;
            foreach ($payments as $p) {
                $left = self::paise($p->amount);
                while ($left > 0 && $i < count($queue)) {
                    $take = min($left, $queue[$i]['left']);
                    if ($take > 0) {
                        $rows[] = ['vendor_payment_id' => $p->id, 'expense_id' => $queue[$i]['expense_id'], 'amount' => $take / 100];
                        if ($queue[$i]['expense_id']) {
                            $allocated[$queue[$i]['expense_id']] = ($allocated[$queue[$i]['expense_id']] ?? 0) + $take;
                        }
                        $queue[$i]['left'] -= $take;
                        $left -= $take;
                    }
                    if ($queue[$i]['left'] <= 0) {
                        $i++;
                    }
                }
                // Anything left over is an advance — it simply lowers the balance below zero.
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('vendor_payment_allocations')->insert($chunk);
            }

            foreach ($bills as $b) {
                $amount = self::paise($b->amount);
                $paid = min($amount, self::paise($b->direct_paid) + ($allocated[$b->id] ?? 0));
                $status = $paid >= $amount ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                if (self::paise($b->paid_amount) !== $paid || $b->status !== $status) {
                    DB::table('expenses')->where('id', $b->id)->update(['paid_amount' => $paid / 100, 'status' => $status]);
                }
            }
        });
    }

    /** Current balance owed to the vendor (negative = advance). */
    public static function balance(Vendor $vendor, ?Carbon $before = null): float
    {
        $bills = Expense::where('vendor_id', $vendor->id)
            ->when($before, fn ($q) => $q->where('date', '<', $before->toDateString()))
            ->selectRaw('COALESCE(SUM(amount - direct_paid), 0) s')->value('s');
        $paid = VendorPayment::where('vendor_id', $vendor->id)
            ->when($before, fn ($q) => $q->where('date', '<', $before->toDateString()))
            ->sum('amount');

        return round((float) $vendor->opening_balance + (float) $bills - (float) $paid, 2);
    }

    /**
     * Statement rows between two dates with a running balance.
     *
     * @return array{opening: float, rows: array<int, array>, closing: float, billed: float, paid: float}
     */
    public static function statement(Vendor $vendor, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $opening = $from ? self::balance($vendor, $from) : (float) $vendor->opening_balance;
        $range = fn ($q) => $q
            ->when($from, fn ($q) => $q->where('date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('date', '<=', $to->toDateString()));

        $bills = $range(Expense::where('vendor_id', $vendor->id))->with('category')->get()
            ->map(fn (Expense $e) => [
                'type' => 'bill', 'id' => $e->id, 'date' => $e->date, 'sort' => $e->date->toDateString().'-0-'.str_pad($e->id, 9, '0', STR_PAD_LEFT),
                'title' => $e->category->name.($e->note ? ' — '.$e->note : ''),
                'debit' => (float) $e->amount, 'credit' => (float) $e->direct_paid,
                'status' => $e->status, 'mode' => $e->payment_mode, 'bill_path' => $e->bill_path,
            ]);

        $payments = $range(VendorPayment::where('vendor_id', $vendor->id))->get()
            ->map(fn (VendorPayment $p) => [
                'type' => 'payment', 'id' => $p->id, 'date' => $p->date, 'sort' => $p->date->toDateString().'-1-'.str_pad($p->id, 9, '0', STR_PAD_LEFT),
                'title' => 'Payment'.($p->note ? ' — '.$p->note : ''),
                'debit' => 0.0, 'credit' => (float) $p->amount, 'mode' => $p->payment_mode,
            ]);

        $running = $opening;
        $rows = $bills->concat($payments)->sortBy('sort')->values()->map(function ($r) use (&$running) {
            $running = round($running + $r['debit'] - $r['credit'], 2);

            return $r + ['balance' => $running];
        })->all();

        return [
            'opening' => $opening,
            'rows' => $rows,
            'closing' => $running,
            'billed' => (float) $bills->sum('debit'),
            'paid' => (float) $bills->sum('credit') + (float) $payments->sum('credit'),
        ];
    }

    private static function paise($v): int
    {
        return (int) round(((float) $v) * 100);
    }
}
