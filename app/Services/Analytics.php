<?php

namespace App\Services;

use App\Models\CashCollection;
use App\Models\Expense;
use App\Models\Setting;
use App\Models\Staff;
use Illuminate\Support\Carbon;

/**
 * Annual Summary: everything for one financial year plus a like-for-like comparison with the previous one.
 * "Like-for-like" = only months that have already happened (or the same days of the current month),
 * so a half-finished year is not compared against a full one.
 */
class Analytics
{
    public static function startMonth(): int
    {
        return (int) Setting::get('fy_start_month', '4');
    }

    public static function label(int $fy): string
    {
        return self::startMonth() === 1 ? "Year {$fy}" : 'FY '.$fy.'-'.substr((string) ($fy + 1), -2);
    }

    /** FYs that have any sales or expenses, newest first (always includes the current FY). */
    public static function years(): array
    {
        $sm = self::startMonth();
        $current = RevenueReport::fyStart(today(), $sm)->year;
        $first = min(array_filter([
            CashCollection::min('date'), Expense::min('date'), Staff::min('joining_date'),
        ]) ?: [today()->toDateString()]);
        $firstFy = RevenueReport::fyStart(Carbon::parse($first), $sm)->year;
        $out = [];
        for ($y = $current; $y >= $firstFy; $y--) {
            $out[$y] = self::label($y);
        }

        return $out;
    }

    public static function build(int $fy): array
    {
        $sm = self::startMonth();
        $from = Carbon::create($fy, $sm, 1);
        $to = $from->copy()->addMonths(11)->endOfMonth()->startOfDay();
        $cutoff = today()->lt($to) ? today() : $to;   // nothing after today counts yet

        $staff = self::staffCost();
        $months = [];
        for ($m = $from->copy(); $m->lte($to); $m->addMonthNoOverflow()) {
            $ym = $m->format('Y-m');
            $end = $m->copy()->endOfMonth();
            $s = CashCollection::totals(CashCollection::between($m, $end));
            $e = Expense::totals(Expense::between($m, $end))['amount'];
            $st = $staff[$ym] ?? 0.0;
            $months[] = [
                'ym' => $ym, 'label' => $m->format('M'), 'full' => $m->format('M Y'), 'future' => $m->gt($cutoff),
                'sales' => $s['total'], 'cash' => $s['cash'], 'upi' => $s['upi'], 'online' => $s['online'], 'days' => $s['days'],
                'expenses' => $e, 'staff' => round($st, 2), 'net' => round($s['total'] - $e - $st, 2),
            ];
        }

        $sum = fn (string $k) => round(array_sum(array_column($months, $k)), 2);
        $totals = [
            'sales' => $sum('sales'), 'expenses' => $sum('expenses'), 'staff' => $sum('staff'), 'net' => $sum('net'),
            'cash' => $sum('cash'), 'upi' => $sum('upi'), 'online' => $sum('online'), 'days' => (int) $sum('days'),
        ];
        $totals['margin'] = $totals['sales'] > 0 ? round($totals['net'] / $totals['sales'] * 100, 1) : 0;
        $totals['avg_day'] = $totals['days'] ? round($totals['sales'] / $totals['days'], 2) : 0;

        // Like-for-like comparison with the previous FY up to the same point in the year.
        $prevFrom = $from->copy()->subYear();
        $prevTo = $cutoff->copy()->subYear();
        $prev = [
            'sales' => CashCollection::totals(CashCollection::between($prevFrom, $prevTo))['total'],
            'expenses' => Expense::totals(Expense::between($prevFrom, $prevTo))['amount'],
        ];
        $growth = fn ($now, $before) => $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
        $ytdSales = CashCollection::totals(CashCollection::between($from, $cutoff))['total'];
        $ytdExp = Expense::totals(Expense::between($from, $cutoff))['amount'];

        // Previous FY month-by-month sales, for the comparison line.
        $prevMonths = [];
        for ($m = $prevFrom->copy(), $i = 0; $i < 12; $m->addMonthNoOverflow(), $i++) {
            $prevMonths[] = CashCollection::totals(CashCollection::between($m, $m->copy()->endOfMonth()))['total'];
        }

        // Month-on-month sales growth (null where there is nothing to compare).
        $mom = [];
        foreach ($months as $i => $m) {
            $before = $i > 0 ? $months[$i - 1]['sales'] : CashCollection::totals(CashCollection::between($from->copy()->subMonth(), $from->copy()->subDay()))['total'];
            $mom[] = $m['future'] || $m['sales'] == 0 ? null : $growth($m['sales'], $before);
        }

        // Cumulative net profit through the year.
        $run = 0;
        $cumulative = array_map(function ($m) use (&$run) {
            $empty = $m['sales'] == 0 && $m['expenses'] == 0 && $m['staff'] == 0;
            if ($m['future'] || ($run == 0 && $empty)) {
                return null; // not started yet / still to come
            }
            $run += $m['net'];

            return round($run, 2);
        }, $months);

        $entries = CashCollection::between($from, $to)->get();
        $weekday = [];
        foreach (range(1, 7) as $d) {
            $g = $entries->filter(fn ($e) => $e->date->dayOfWeekIso === $d);
            $weekday[] = ['label' => Carbon::create(2024, 1, $d)->format('D'), 'avg' => $g->count() ? round($g->avg('total'), 2) : 0, 'days' => $g->count()];
        }

        $categories = Expense::byCategory(Expense::between($from, $to));

        return [
            'fy' => $fy,
            'label' => self::label($fy),
            'range' => $from->format('M Y').' – '.$to->format('M Y'),
            'months' => $months,
            'totals' => $totals,
            'compare' => [
                'prev_label' => self::label($fy - 1),
                'upto' => $cutoff,
                'sales_now' => $ytdSales, 'sales_prev' => $prev['sales'], 'sales_growth' => $growth($ytdSales, $prev['sales']),
                'exp_now' => $ytdExp, 'exp_prev' => $prev['expenses'], 'exp_growth' => $growth($ytdExp, $prev['expenses']),
            ],
            'prev_months' => $prevMonths,
            'mom' => $mom,
            'cumulative' => $cumulative,
            'weekday' => $weekday,
            'categories' => $categories,
            'best_days' => $entries->sortByDesc(fn ($e) => (float) $e->total)->take(5)->values(),
            'worst_days' => $entries->filter(fn ($e) => (float) $e->total > 0)->sortBy(fn ($e) => (float) $e->total)->take(5)->values(),
            'insights' => self::insights($months, $totals, $categories, $weekday, $growth($ytdSales, $prev['sales'])),
        ];
    }

    private static function insights(array $months, array $t, array $cats, array $weekday, ?float $growth): array
    {
        $out = [];
        $past = array_values(array_filter($months, fn ($m) => ! $m['future'] && $m['sales'] > 0));
        if (! $past) {
            return ['No sales recorded in this year yet.'];
        }
        usort($past, fn ($a, $b) => $b['net'] <=> $a['net']);
        $out[] = 'Most profitable month: '.$past[0]['full'].' ('.money($past[0]['net']).' net).';
        if (count($past) > 1) {
            $w = end($past);
            $out[] = 'Least profitable month: '.$w['full'].' ('.money($w['net']).' net).';
        }
        if ($growth !== null) {
            $out[] = 'Sales are '.($growth >= 0 ? 'up ' : 'down ').abs($growth).'% compared with the same period last year.';
        }
        if ($cats) {
            $share = $t['expenses'] > 0 ? round($cats[0]['amount'] / $t['expenses'] * 100) : 0;
            $out[] = 'Biggest expense: '.$cats[0]['name'].' — '.money($cats[0]['amount'])." ({$share}% of all expenses).";
        }
        $busy = collect($weekday)->sortByDesc('avg')->first();
        $quiet = collect($weekday)->filter(fn ($d) => $d['days'] > 0)->sortBy('avg')->first();
        if ($busy && $busy['avg'] > 0) {
            $out[] = "Busiest day: {$busy['label']} (avg ".money($busy['avg']).')'.($quiet ? "; quietest: {$quiet['label']} (avg ".money($quiet['avg']).').' : '.');
        }
        if ($t['sales'] > 0) {
            $digital = round(($t['upi'] + $t['online']) / $t['sales'] * 100);
            $out[] = "Digital payments (UPI + online) are {$digital}% of sales; cash is ".(100 - $digital).'%.';
            $out[] = 'Staff cost is '.round($t['staff'] / $t['sales'] * 100).'% of sales; other expenses '.round($t['expenses'] / $t['sales'] * 100).'%.';
        }

        return $out;
    }

    /** Staff cost earned per month across all staff: ['Y-m' => amount]. */
    private static function staffCost(): array
    {
        $out = [];
        foreach (Staff::all() as $s) {
            foreach (StaffLedger::months($s) as $m) {
                $out[$m['month']] = ($out[$m['month']] ?? 0) + $m['earned'];
            }
        }

        return $out;
    }
}
