<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Staff;
use App\Models\StaffPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Salary maths for staff.
 *
 * Monthly salary: per-day = salary ÷ days in that month. Every employed day up to today counts as worked
 *   unless marked Absent (−1 day) or Half day (−½ day). Paid leave and week-off are paid. Unmarked = worked.
 * Daily wage:     earns rate × (Present + ½ × Half day). Leave / week-off / unmarked are unpaid.
 * Each month's work value is rounded to the nearest rupee.
 *
 * Month row:  opening + work value + bonus − deductions − paid (salary + advance + bonus) = closing,
 *             and closing carries forward as the next month's opening (positive = you owe staff,
 *             negative = advance not yet recovered).
 */
class StaffLedger
{
    /** Month-by-month ledger from the joining month (or $from) to $to (default: this month). */
    public static function months(Staff $staff, ?string $from = null, ?string $to = null): Collection
    {
        $start = $staff->joining_date->copy()->startOfMonth();
        $end = Carbon::parse(($to ?? now()->format('Y-m')).'-01')->startOfMonth();
        if ($staff->leaving_date && $staff->leaving_date->copy()->startOfMonth()->lt($end)) {
            // Keep showing months after leaving only if money moved in them.
            $lastPay = $staff->payments()->max('date');
            $leftMonth = $staff->leaving_date->copy()->startOfMonth();
            $payMonth = $lastPay ? Carbon::parse($lastPay)->startOfMonth() : $leftMonth;
            $end = $payMonth->gt($leftMonth) ? ($payMonth->gt($end) ? $end : $payMonth) : $leftMonth;
        }
        if ($end->lt($start)) {
            $end = $start->copy();
        }

        $att = Attendance::where('staff_id', $staff->id)->get(['date', 'status'])
            ->groupBy(fn ($a) => $a->date->format('Y-m'));
        $pays = StaffPayment::where('staff_id', $staff->id)->orderBy('date')->orderBy('id')->get()
            ->groupBy(fn ($p) => $p->date->format('Y-m'));

        $rows = collect();
        $balance = (float) $staff->opening_balance;
        for ($m = $start->copy(); $m->lte($end); $m->addMonthNoOverflow()) {
            $ym = $m->format('Y-m');
            $row = self::computeMonth($staff, $m, $att->get($ym, collect()), $pays->get($ym, collect()));
            $row['opening'] = round($balance, 2);
            $balance = round($balance + $row['earned'] - $row['paid'], 2);
            $row['closing'] = $balance;
            $rows->push($row);
        }

        return $from ? $rows->filter(fn ($r) => $r['month'] >= $from)->values() : $rows;
    }

    /** Single month (with correct opening carried in). */
    public static function month(Staff $staff, string $ym): array
    {
        $row = self::months($staff, null, max($ym, $staff->joining_date->format('Y-m')))->firstWhere('month', $ym);

        return $row ?? self::computeMonth($staff, Carbon::parse($ym.'-01'), collect(), collect())
            + ['opening' => (float) $staff->opening_balance, 'closing' => (float) $staff->opening_balance];
    }

    /** Current balance: positive = you owe the staff member, negative = they owe you. */
    public static function balance(Staff $staff): float
    {
        return (float) (self::months($staff)->last()['closing'] ?? $staff->opening_balance);
    }

    /**
     * Financial years the staff member has worked in, newest first: [startYear => 'FY 2026-27'].
     */
    public static function financialYears(Staff $staff, int $startMonth = 4): array
    {
        $first = RevenueReport::fyStart($staff->joining_date, $startMonth)->year;
        $last = RevenueReport::fyStart(today(), $startMonth)->year;
        $out = [];
        for ($y = $last; $y >= $first; $y--) {
            $out[$y] = $startMonth === 1 ? "Year {$y}" : 'FY '.$y.'-'.substr((string) ($y + 1), -2);
        }

        return $out;
    }

    /** Month rows that fall inside one financial year (with correct carried-in opening). */
    public static function yearRows(Staff $staff, int $fyStartYear, int $startMonth = 4): Collection
    {
        $from = Carbon::create($fyStartYear, $startMonth, 1);
        $to = $from->copy()->addMonths(11);

        return self::months($staff)
            ->filter(fn ($r) => $r['month'] >= $from->format('Y-m') && $r['month'] <= $to->format('Y-m'))
            ->values();
    }

    /** Totals for a set of month rows (e.g. a financial year). */
    public static function totals(Collection $rows): array
    {
        return [
            'opening' => (float) ($rows->first()['opening'] ?? 0),
            'closing' => (float) ($rows->last()['closing'] ?? 0),
            'work_value' => round($rows->sum('work_value'), 2),
            'bonus' => round($rows->sum('bonus'), 2),
            'deductions' => round($rows->sum('deductions'), 2),
            'earned' => round($rows->sum('earned'), 2),
            'salary' => round($rows->sum('salary_paid'), 2),
            'advance' => round($rows->sum('advance_paid'), 2),
            'paid' => round($rows->sum('paid'), 2),
            'present' => $rows->sum(fn ($r) => $r['counts']['P']),
            'absent' => $rows->sum(fn ($r) => $r['counts']['A']),
            'half' => $rows->sum(fn ($r) => $r['counts']['H']),
        ];
    }

    private static function computeMonth(Staff $staff, Carbon $monthStart, Collection $attendance, Collection $payments): array
    {
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();
        $daysInMonth = $monthStart->daysInMonth;

        // Employed window inside this month, never beyond today.
        $from = $staff->joining_date->gt($monthStart) ? $staff->joining_date->copy() : $monthStart->copy();
        $to = $monthEnd->copy();
        if ($staff->leaving_date && $staff->leaving_date->lt($to)) {
            $to = $staff->leaving_date->copy();
        }
        if (today()->lt($to)) {
            $to = today();
        }
        $periodDays = $from->lte($to) ? (int) $from->diffInDays($to) + 1 : 0;

        $counts = ['P' => 0, 'A' => 0, 'H' => 0, 'L' => 0, 'O' => 0];
        foreach ($attendance as $a) {
            if ($a->date->betweenIncluded($from, $to) && isset($counts[$a->status])) {
                $counts[$a->status]++;
            }
        }
        $marked = array_sum($counts);
        $unmarked = max(0, $periodDays - $marked);

        $rate = (float) $staff->salary_amount;
        if ($staff->salary_type === 'daily') {
            $paidDays = $counts['P'] + 0.5 * $counts['H'];
            $workValue = $rate * $paidDays;
            $perDay = $rate;
        } else {
            $perDay = $rate / $daysInMonth;
            $paidDays = max(0, $periodDays - $counts['A'] - 0.5 * $counts['H']);
            $workValue = $perDay * $paidDays;
        }

        $sum = fn (string $type) => round((float) $payments->where('type', $type)->sum('amount'), 2);
        $bonus = $sum('bonus');
        $deductions = $sum('deduction');
        $salaryPaid = $sum('salary');
        $advancePaid = $sum('advance');
        $workValue = (float) round($workValue); // salaries are paid in whole rupees

        return [
            'month' => $monthStart->format('Y-m'),
            'label' => $monthStart->format('M Y'),
            'days_in_month' => $daysInMonth,
            'period_days' => $periodDays,
            'counts' => $counts,
            'unmarked' => $unmarked,
            'paid_days' => $paidDays,
            'per_day' => round($perDay, 2),
            'work_value' => $workValue,
            'bonus' => $bonus,
            'deductions' => $deductions,
            'earned' => round($workValue + $bonus - $deductions, 2),
            'salary_paid' => $salaryPaid,
            'advance_paid' => $advancePaid,
            'paid' => round($salaryPaid + $advancePaid + $bonus, 2),
            'payments' => $payments->values(),
            'in_progress' => $monthStart->isSameMonth(today()),
        ];
    }
}
