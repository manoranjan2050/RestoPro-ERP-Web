<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\Staff;
use App\Models\StaffPayment;
use App\Models\Vendor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Master Ledger: every rupee that actually moved, from every module, in one timeline.
 *
 * It is a live view (UNION over the source tables), not a copy — so editing or deleting any collection,
 * expense, vendor payment or staff payment is reflected immediately and can never double-post.
 *
 *  IN   sales (cash / UPI / online, one row per mode)
 *  OUT  expenses paid at purchase (direct_paid, on paid_on), vendor khata payments, staff salary/advance/bonus
 *  Not money movements, so excluded: unpaid bills (see vendor dues) and staff deductions.
 *
 * Money "accounts": cash · bank (UPI, bank transfer, card) · online (Swiggy/Zomato-type platform sales).
 */
class MasterLedger
{
    public const SOURCES = [
        'sale' => 'Sales',
        'expense' => 'Expenses',
        'vendor_payment' => 'Vendor payments',
        'staff_payment' => 'Staff payments',
    ];

    public const ACCOUNTS = ['cash' => 'Cash', 'bank' => 'Bank / UPI', 'online' => 'Online platforms'];

    /** Payment mode → account. */
    public static function account(?string $mode): string
    {
        return match ($mode) {
            'cash' => 'cash',
            'online' => 'online',
            default => 'bank',
        };
    }

    public static function union(): Builder
    {
        $sale = fn (string $mode) => DB::table('cash_collections')
            ->where($mode, '>', 0)
            ->selectRaw("date, 'sale' as source, id as source_id, null as kind, '{$mode}' as mode, {$mode} as amount_in, 0 as amount_out, null as ref_id, note");

        $expenses = DB::table('expenses')
            ->where('direct_paid', '>', 0)
            ->selectRaw("COALESCE(paid_on, date) as date, 'expense' as source, id as source_id, category_id as kind, payment_mode as mode, 0 as amount_in, direct_paid as amount_out, vendor_id as ref_id, note");

        $vendorPayments = DB::table('vendor_payments')
            ->selectRaw("date, 'vendor_payment' as source, id as source_id, null as kind, payment_mode as mode, 0 as amount_in, amount as amount_out, vendor_id as ref_id, note");

        $staffPayments = DB::table('staff_payments')
            ->where('type', '!=', 'deduction')
            ->selectRaw("date, 'staff_payment' as source, id as source_id, type as kind, payment_mode as mode, 0 as amount_in, amount as amount_out, staff_id as ref_id, note");

        return $sale('cash')->unionAll($sale('upi'))->unionAll($sale('online'))
            ->unionAll($expenses)->unionAll($vendorPayments)->unionAll($staffPayments);
    }

    /** Base query over the union with optional filters. */
    public static function query(array $f = []): Builder
    {
        $q = DB::query()->fromSub(self::union(), 'l');

        if (! empty($f['from'])) {
            $q->where('date', '>=', Carbon::parse($f['from'])->toDateString());
        }
        if (! empty($f['to'])) {
            $q->where('date', '<=', Carbon::parse($f['to'])->toDateString());
        }
        if (! empty($f['source'])) {
            $q->where('source', $f['source']);
        }
        if (! empty($f['direction'])) {
            $q->where($f['direction'] === 'in' ? 'amount_in' : 'amount_out', '>', 0);
        }
        if (! empty($f['account'])) {
            match ($f['account']) {
                'cash' => $q->where('mode', 'cash'),
                'online' => $q->where('mode', 'online'),
                default => $q->where(fn ($w) => $w->whereNotIn('mode', ['cash', 'online'])->orWhereNull('mode')),
            };
        }

        return $q;
    }

    /** Net flow (in − out) before a date, for the running balance's opening. */
    public static function balanceBefore(string $date, array $f = []): float
    {
        $f = array_merge($f, ['from' => null, 'to' => Carbon::parse($date)->subDay()->toDateString()]);
        $r = self::query($f)->selectRaw('COALESCE(SUM(amount_in),0) i, COALESCE(SUM(amount_out),0) o')->first();

        return round((float) $r->i - (float) $r->o, 2);
    }

    /** Rows oldest → newest with title/subtitle/link and running balance. */
    public static function rows(array $f, float $opening): Collection
    {
        $raw = self::query($f)
            ->orderBy('date')
            ->orderByRaw("CASE source WHEN 'sale' THEN 0 WHEN 'expense' THEN 1 WHEN 'vendor_payment' THEN 2 ELSE 3 END")
            ->orderBy('source_id')
            ->get();

        $cats = ExpenseCategory::pluck('name', 'id');
        $vendors = Vendor::pluck('name', 'id');
        $staff = Staff::pluck('name', 'id');
        $balance = $opening;

        return $raw->map(function ($r) use (&$balance, $cats, $vendors, $staff) {
            $date = Carbon::parse($r->date);
            $in = (float) $r->amount_in;
            $out = (float) $r->amount_out;
            $balance = round($balance + $in - $out, 2);

            [$title, $sub, $url] = match ($r->source) {
                'sale' => ['Sales · '.match ($r->mode) { 'cash' => 'Cash', 'upi' => 'UPI', default => 'Online' }, $r->note,
                    route('collections', ['m' => $date->format('Y-m')])],
                'expense' => [($cats[(int) $r->kind] ?? 'Expense'), trim(($vendors[$r->ref_id] ?? '').($r->note ? ' · '.$r->note : ''), ' ·'),
                    route('expenses', array_filter(['m' => $date->format('Y-m'), 'v' => $r->ref_id]))],
                'vendor_payment' => ['Paid '.($vendors[$r->ref_id] ?? 'vendor'), $r->note ?: 'Khata payment',
                    route('vendors.khata', $r->ref_id)],
                default => [(StaffPayment::TYPES[$r->kind][0] ?? 'Staff').' · '.($staff[$r->ref_id] ?? 'staff'), $r->note,
                    route('staff.show', ['staff' => $r->ref_id, 'tab' => 'payments'])],
            };

            return [
                'key' => $r->source.'-'.$r->source_id.'-'.$r->mode,
                'date' => $date,
                'source' => $r->source,
                'mode' => $r->mode,
                'account' => self::account($r->mode),
                'title' => $title,
                'sub' => $sub,
                'url' => $url,
                'in' => $in,
                'out' => $out,
                'balance' => $balance,
            ];
        });
    }

    /** In/out per account and per source for a filtered range. */
    public static function summary(array $f): array
    {
        $byMode = self::query($f)->groupBy('mode', 'source')
            ->selectRaw('mode, source, COALESCE(SUM(amount_in),0) i, COALESCE(SUM(amount_out),0) o')->get();

        $accounts = array_fill_keys(array_keys(self::ACCOUNTS), ['in' => 0.0, 'out' => 0.0]);
        $sources = array_fill_keys(array_keys(self::SOURCES), ['in' => 0.0, 'out' => 0.0]);
        foreach ($byMode as $r) {
            $a = self::account($r->mode);
            $accounts[$a]['in'] += (float) $r->i;
            $accounts[$a]['out'] += (float) $r->o;
            $sources[$r->source]['in'] += (float) $r->i;
            $sources[$r->source]['out'] += (float) $r->o;
        }
        $in = array_sum(array_column($accounts, 'in'));
        $out = array_sum(array_column($accounts, 'out'));

        return ['in' => round($in, 2), 'out' => round($out, 2), 'net' => round($in - $out, 2), 'accounts' => $accounts, 'sources' => $sources];
    }

    /** All-time balance of each account up to a date (cash in hand, bank, online). */
    public static function accountBalances(string $upTo): array
    {
        $out = [];
        foreach (array_keys(self::ACCOUNTS) as $a) {
            $r = self::query(['to' => $upTo, 'account' => $a])->selectRaw('COALESCE(SUM(amount_in),0) i, COALESCE(SUM(amount_out),0) o')->first();
            $out[$a] = round((float) $r->i - (float) $r->o, 2);
        }

        return $out;
    }
}
