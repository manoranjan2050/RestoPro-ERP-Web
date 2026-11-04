<?php

namespace App\Services;

use App\Models\CashCollection;
use App\Models\Expense;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\StaffPayment;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Builds every report as plain data so one template renders the on-screen preview, the PDF and the Excel file:
 *
 *  [ 'title', 'subtitle', 'kpis' => [[label, value, type]], 'sections' => [[
 *        'title', 'columns' => [[label, type]], 'rows' => [[...]], 'totals' => [...]|null ]], 'notes' => [] ]
 *
 * Column types: text · money · int · num · pct · date.
 * Profit uses bills by bill date (paid or not) and staff cost = work value + bonus − deductions earned that month.
 */
class ReportBuilder
{
    public const TYPES = [
        'monthly' => ['Monthly summary', 'Sales, expenses, staff cost and profit for one month', 'month'],
        'yearly' => ['Yearly summary', 'Month-by-month profit for a financial year', 'fy'],
        'sales' => ['Sales report', 'Daily sales by payment mode', 'range'],
        'expenses' => ['Expense report', 'By category, by vendor and every bill', 'range'],
        'vendor_dues' => ['Vendor dues', 'What you owe each vendor today', 'none'],
        'salary' => ['Salary sheet', 'Attendance, earnings and payments for every staff member', 'month'],
    ];

    /** v1 reports, plus the POS reports in Advanced mode. */
    public static function types(): array
    {
        return self::TYPES + (\App\Support\Sales::advanced() ? SalesReports::TYPES : []);
    }

    public static function build(string $type, array $p): array
    {
        if (array_key_exists($type, SalesReports::TYPES)) {
            return SalesReports::build($type, $p);
        }

        return match ($type) {
            'monthly' => self::monthly($p['month']),
            'yearly' => self::yearly((int) $p['fy']),
            'sales' => self::sales($p['from'], $p['to']),
            'expenses' => self::expenses($p['from'], $p['to']),
            'vendor_dues' => self::vendorDues(),
            'salary' => self::salary($p['month']),
            default => throw new InvalidArgumentException("Unknown report {$type}"),
        };
    }

    // ---------------------------------------------------------------- monthly

    public static function monthly(string $ym): array
    {
        $from = Carbon::parse($ym.'-01');
        $to = $from->copy()->endOfMonth()->startOfDay();
        $sales = CashCollection::totals(CashCollection::between($from, $to));
        $expQ = Expense::between($from, $to);
        $exp = Expense::totals($expQ);
        $staff = self::staffCostByMonth()->get($ym, ['total' => 0.0, 'people' => []]);
        $gross = $sales['total'] - $exp['amount'];
        $net = $gross - $staff['total'];
        $pct = fn ($v, $of) => $of > 0 ? round($v / $of * 100, 1) : 0;

        $byCat = Expense::byCategory($expQ);
        $catRows = array_map(function ($c) use ($from, $to, $exp, $pct) {
            $t = Expense::totals(Expense::between($from, $to)->where('category_id', $c['id']));

            return [$c['name'], $t['count'], $t['amount'], $t['paid'], $t['unpaid'], $pct($t['amount'], $exp['amount'])];
        }, $byCat);

        $daily = CashCollection::between($from, $to)->orderBy('date')->get()
            ->map(fn ($c) => [$c->date->toDateString(), $c->date->format('D'), (float) $c->cash, (float) $c->upi, (float) $c->online, (float) $c->total])->all();

        return [
            'title' => 'Monthly summary',
            'subtitle' => $from->format('F Y'),
            'kpis' => [
                ['Sales', $sales['total'], 'money'],
                ['Expenses', $exp['amount'], 'money'],
                ['Staff cost', $staff['total'], 'money'],
                ['Net profit', $net, 'money'],
                ['Gross profit (before staff)', $gross, 'money'],
                ['Net margin', $pct($net, $sales['total']), 'pct'],
            ],
            'sections' => [
                [
                    'title' => 'Profit & loss',
                    'columns' => [['Line', 'text'], ['Amount', 'money'], ['% of sales', 'pct']],
                    'rows' => [
                        ['Sales', $sales['total'], 100],
                        ['− Expenses (all bills, paid or unpaid)', $exp['amount'], $pct($exp['amount'], $sales['total'])],
                        ['= Gross profit', $gross, $pct($gross, $sales['total'])],
                        ['− Staff cost (earned this month)', $staff['total'], $pct($staff['total'], $sales['total'])],
                        ['= Net profit', $net, $pct($net, $sales['total'])],
                    ],
                    'totals' => null,
                ],
                [
                    'title' => 'Sales by payment mode',
                    'columns' => [['Mode', 'text'], ['Amount', 'money'], ['Share', 'pct']],
                    'rows' => [
                        ['Cash', $sales['cash'], $pct($sales['cash'], $sales['total'])],
                        ['UPI', $sales['upi'], $pct($sales['upi'], $sales['total'])],
                        ['Online', $sales['online'], $pct($sales['online'], $sales['total'])],
                    ],
                    'totals' => ['Total ('.$sales['days'].' days)', $sales['total'], 100],
                ],
                [
                    'title' => 'Expenses by category',
                    'columns' => [['Category', 'text'], ['Bills', 'int'], ['Amount', 'money'], ['Paid', 'money'], ['Unpaid', 'money'], ['Share', 'pct']],
                    'rows' => $catRows,
                    'totals' => ['Total', $exp['count'], $exp['amount'], $exp['paid'], $exp['unpaid'], 100],
                ],
                [
                    'title' => 'Staff cost',
                    'columns' => [['Staff', 'text'], ['Earned', 'money']],
                    'rows' => $staff['people'],
                    'totals' => ['Total', $staff['total']],
                ],
                [
                    'title' => 'Daily sales',
                    'columns' => [['Date', 'date'], ['Day', 'text'], ['Cash', 'money'], ['UPI', 'money'], ['Online', 'money'], ['Total', 'money']],
                    'rows' => $daily,
                    'totals' => ['Total', '', $sales['cash'], $sales['upi'], $sales['online'], $sales['total']],
                ],
            ],
            'notes' => [
                'Expenses count every bill dated in the month, whether paid or still unpaid (udhaar).',
                'Staff cost = work value + bonus − deductions earned in the month; advances are not a cost.',
            ],
        ];
    }

    // ----------------------------------------------------------------- yearly

    public static function yearly(int $fyStartYear): array
    {
        $startMonth = (int) Setting::get('fy_start_month', '4');
        $from = Carbon::create($fyStartYear, $startMonth, 1);
        $to = $from->copy()->addMonths(11)->endOfMonth()->startOfDay();
        $staffCost = self::staffCostByMonth();
        $pct = fn ($v, $of) => $of > 0 ? round($v / $of * 100, 1) : 0;

        $rows = [];
        $t = ['sales' => 0, 'exp' => 0, 'staff' => 0];
        for ($m = $from->copy(); $m->lte($to); $m->addMonthNoOverflow()) {
            $s = CashCollection::totals(CashCollection::between($m, $m->copy()->endOfMonth()))['total'];
            $e = Expense::totals(Expense::between($m, $m->copy()->endOfMonth()))['amount'];
            $st = $staffCost->get($m->format('Y-m'))['total'] ?? 0.0;
            $net = $s - $e - $st;
            $rows[] = [$m->format('M Y'), $s, $e, $st, $s - $e, $net, $pct($net, $s)];
            $t['sales'] += $s;
            $t['exp'] += $e;
            $t['staff'] += $st;
        }
        $net = $t['sales'] - $t['exp'] - $t['staff'];
        $best = collect($rows)->sortByDesc(5)->first();

        $byCat = Expense::byCategory(Expense::between($from, $to));
        $label = $startMonth === 1 ? "Year {$fyStartYear}" : 'FY '.$fyStartYear.'-'.substr((string) ($fyStartYear + 1), -2);

        return [
            'title' => 'Yearly summary',
            'subtitle' => $label.' ('.$from->format('M Y').' – '.$to->format('M Y').')',
            'kpis' => [
                ['Sales', $t['sales'], 'money'],
                ['Expenses', $t['exp'], 'money'],
                ['Staff cost', $t['staff'], 'money'],
                ['Net profit', $net, 'money'],
                ['Net margin', $pct($net, $t['sales']), 'pct'],
                ['Best month', $best && $best[1] > 0 ? $best[0] : '—', 'text'],
            ],
            'sections' => [
                [
                    'title' => 'Month by month',
                    'columns' => [['Month', 'text'], ['Sales', 'money'], ['Expenses', 'money'], ['Staff cost', 'money'], ['Gross profit', 'money'], ['Net profit', 'money'], ['Margin', 'pct']],
                    'rows' => $rows,
                    'totals' => ['Full year', $t['sales'], $t['exp'], $t['staff'], $t['sales'] - $t['exp'], $net, $pct($net, $t['sales'])],
                ],
                [
                    'title' => 'Expenses by category',
                    'columns' => [['Category', 'text'], ['Amount', 'money'], ['Share', 'pct']],
                    'rows' => array_map(fn ($c) => [$c['name'], $c['amount'], $pct($c['amount'], $t['exp'])], $byCat),
                    'totals' => ['Total', $t['exp'], 100],
                ],
            ],
            'notes' => ['Months after today show zero until they happen.'],
        ];
    }

    // ------------------------------------------------------------------ sales

    public static function sales(string $from, string $to): array
    {
        [$f, $t] = [Carbon::parse($from), Carbon::parse($to)];
        $q = CashCollection::between($f, $t);
        $tot = CashCollection::totals($q);
        $entries = (clone $q)->orderBy('date')->get();
        $byDow = $entries->groupBy(fn ($e) => $e->date->dayOfWeekIso)->sortKeys()
            ->map(fn ($g) => [$g->first()->date->format('l'), $g->count(), round($g->avg('total'), 2), (float) $g->max('total')])->values()->all();

        return [
            'title' => 'Sales report',
            'subtitle' => $f->format('d M Y').' – '.$t->format('d M Y'),
            'kpis' => [
                ['Total sales', $tot['total'], 'money'],
                ['Days entered', $tot['days'], 'int'],
                ['Average / day', $tot['days'] ? $tot['total'] / $tot['days'] : 0, 'money'],
                ['Cash', $tot['cash'], 'money'],
                ['UPI', $tot['upi'], 'money'],
                ['Online', $tot['online'], 'money'],
            ],
            'sections' => [
                [
                    'title' => 'Daily sales',
                    'columns' => [['Date', 'date'], ['Day', 'text'], ['Cash', 'money'], ['UPI', 'money'], ['Online', 'money'], ['Total', 'money'], ['Note', 'text']],
                    'rows' => $entries->map(fn ($c) => [$c->date->toDateString(), $c->date->format('D'), (float) $c->cash, (float) $c->upi, (float) $c->online, (float) $c->total, (string) $c->note])->all(),
                    'totals' => ['Total', '', $tot['cash'], $tot['upi'], $tot['online'], $tot['total'], ''],
                ],
                [
                    'title' => 'By day of week',
                    'columns' => [['Day', 'text'], ['Days', 'int'], ['Average', 'money'], ['Best', 'money']],
                    'rows' => $byDow,
                    'totals' => null,
                ],
            ],
            'notes' => [],
        ];
    }

    // --------------------------------------------------------------- expenses

    public static function expenses(string $from, string $to): array
    {
        [$f, $t] = [Carbon::parse($from), Carbon::parse($to)];
        $q = Expense::between($f, $t);
        $tot = Expense::totals($q);
        $bills = (clone $q)->with('category', 'vendor')->orderBy('date')->orderBy('id')->get();
        $pct = fn ($v) => $tot['amount'] > 0 ? round($v / $tot['amount'] * 100, 1) : 0;

        $group = fn (Collection $g, string $name) => [$name, $g->count(), (float) $g->sum('amount'), (float) $g->sum('paid_amount'),
            round((float) $g->sum('amount') - (float) $g->sum('paid_amount'), 2), $pct((float) $g->sum('amount'))];

        $byCat = $bills->groupBy('category_id')->map(fn ($g) => $group($g, $g->first()->category->name))->sortByDesc(2)->values()->all();
        $byVendor = $bills->groupBy(fn ($e) => $e->vendor_id ?? 0)
            ->map(fn ($g) => $group($g, $g->first()->vendor->name ?? '(no vendor)'))->sortByDesc(2)->values()->all();

        return [
            'title' => 'Expense report',
            'subtitle' => $f->format('d M Y').' – '.$t->format('d M Y'),
            'kpis' => [
                ['Total expenses', $tot['amount'], 'money'],
                ['Bills', $tot['count'], 'int'],
                ['Paid', $tot['paid'], 'money'],
                ['Unpaid', $tot['unpaid'], 'money'],
            ],
            'sections' => [
                ['title' => 'By category', 'columns' => self::groupCols('Category'), 'rows' => $byCat,
                    'totals' => ['Total', $tot['count'], $tot['amount'], $tot['paid'], $tot['unpaid'], 100]],
                ['title' => 'By vendor', 'columns' => self::groupCols('Vendor'), 'rows' => $byVendor,
                    'totals' => ['Total', $tot['count'], $tot['amount'], $tot['paid'], $tot['unpaid'], 100]],
                [
                    'title' => 'All bills',
                    'columns' => [['Date', 'date'], ['Category', 'text'], ['Vendor', 'text'], ['Amount', 'money'], ['Paid', 'money'], ['Status', 'text'], ['Mode', 'text'], ['Note', 'text']],
                    'rows' => $bills->map(fn ($e) => [$e->date->toDateString(), $e->category->name, $e->vendor->name ?? '', (float) $e->amount,
                        (float) $e->paid_amount, ucfirst($e->status), Expense::MODES[$e->payment_mode] ?? '', (string) $e->note])->all(),
                    'totals' => ['Total', '', '', $tot['amount'], $tot['paid'], '', '', ''],
                ],
            ],
            'notes' => ['Paid includes amounts settled later through vendor khata payments.'],
        ];
    }

    // ------------------------------------------------------------ vendor dues

    public static function vendorDues(): array
    {
        $vendors = Vendor::query()->select('vendors.*')->withDue()->orderBy('name')->get();
        $rows = $vendors->map(function (Vendor $v) {
            $open = Expense::where('vendor_id', $v->id)->whereIn('status', ['unpaid', 'partial']);
            $oldest = (clone $open)->min('date');

            return [$v->name, (string) $v->mobile, (float) $v->opening_balance, (float) $v->total_bills, (float) $v->total_bills - (float) $v->bills_net,
                (float) $v->payments_total, $v->due(), (clone $open)->count(), $oldest ? Carbon::parse($oldest)->toDateString() : '',
                $oldest ? (int) Carbon::parse($oldest)->diffInDays(today()) : ''];
        })->sortByDesc(6)->values();

        $owed = $rows->filter(fn ($r) => $r[6] > 0);

        return [
            'title' => 'Vendor dues',
            'subtitle' => 'As of '.today()->format('d M Y'),
            'kpis' => [
                ['Total owed', $owed->sum(6), 'money'],
                ['Vendors with dues', $owed->count(), 'int'],
                ['Advances paid', abs($rows->filter(fn ($r) => $r[6] < 0)->sum(6)), 'money'],
                ['Oldest unpaid bill', $rows->max(9) !== null && $rows->max(9) !== '' ? $rows->max(9).' days' : '—', 'text'],
            ],
            'sections' => [[
                'title' => 'Vendor balances',
                'columns' => [['Vendor', 'text'], ['Mobile', 'text'], ['Opening', 'money'], ['Billed', 'money'], ['Paid at purchase', 'money'],
                    ['Khata payments', 'money'], ['Balance', 'money'], ['Open bills', 'int'], ['Oldest open', 'date'], ['Days', 'int']],
                'rows' => $rows->all(),
                'totals' => ['Total', '', $rows->sum(2), $rows->sum(3), $rows->sum(4), $rows->sum(5), $rows->sum(6), $rows->sum(7), '', ''],
            ]],
            'notes' => ['Balance = opening + billed − paid at purchase − khata payments. Negative = advance paid to the vendor.'],
        ];
    }

    // ----------------------------------------------------------------- salary

    public static function salary(string $ym): array
    {
        $from = Carbon::parse($ym.'-01');
        $to = $from->copy()->endOfMonth()->startOfDay();
        $staff = Staff::where('joining_date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('leaving_date')->orWhere('leaving_date', '>=', $from->toDateString()))
            ->orderBy('name')->get();

        $rows = $staff->map(function (Staff $s) use ($ym) {
            $m = StaffLedger::month($s, $ym);

            return [$s->name, (string) $s->designation, $s->salary_type === 'daily' ? 'Daily' : 'Monthly', (float) $s->salary_amount,
                $m['counts']['P'], $m['counts']['H'], $m['counts']['A'], $m['counts']['L'], $m['counts']['O'], $m['paid_days'],
                $m['work_value'], $m['bonus'], $m['deductions'], $m['opening'], $m['salary_paid'], $m['advance_paid'], $m['closing']];
        });
        $sum = fn (int $i) => round($rows->sum($i), 2);

        return [
            'title' => 'Salary sheet',
            'subtitle' => $from->format('F Y').($from->isSameMonth(today()) ? ' (up to today)' : ''),
            'kpis' => [
                ['Staff', $rows->count(), 'int'],
                ['Work value', $sum(10), 'money'],
                ['Salary paid', $sum(14), 'money'],
                ['Advances', $sum(15), 'money'],
                ['Balance to pay', $rows->filter(fn ($r) => $r[16] > 0)->sum(16), 'money'],
                ['Advance to recover', abs($rows->filter(fn ($r) => $r[16] < 0)->sum(16)), 'money'],
            ],
            'sections' => [[
                'title' => 'Salary sheet',
                'columns' => [['Name', 'text'], ['Role', 'text'], ['Type', 'text'], ['Rate', 'money'], ['P', 'int'], ['H', 'int'], ['A', 'int'],
                    ['L', 'int'], ['O', 'int'], ['Paid days', 'num'], ['Work value', 'money'], ['Bonus', 'money'], ['Deduction', 'money'],
                    ['Opening', 'money'], ['Salary paid', 'money'], ['Advance', 'money'], ['Closing', 'money']],
                'rows' => $rows->all(),
                'totals' => ['Total', '', '', '', $sum(4), $sum(5), $sum(6), $sum(7), $sum(8), '', $sum(10), $sum(11), $sum(12), $sum(13), $sum(14), $sum(15), $sum(16)],
            ]],
            'notes' => [
                'P present · H half day · A absent · L paid leave · O week off. Monthly staff: unmarked days are paid; daily staff: only P and ½H.',
                'Closing = opening + work value + bonus − deduction − salary − advance − bonus paid; it carries into next month.',
            ],
        ];
    }

    // ---------------------------------------------------------------- helpers

    /** Staff cost (earned) per month for all staff: ['Y-m' => ['total' => x, 'people' => [[name, earned]]]]. */
    private static function staffCostByMonth(): Collection
    {
        $out = [];
        foreach (Staff::all() as $s) {
            foreach (StaffLedger::months($s) as $m) {
                if ($m['earned'] == 0) {
                    continue;
                }
                $out[$m['month']]['total'] = ($out[$m['month']]['total'] ?? 0) + $m['earned'];
                $out[$m['month']]['people'][] = [$s->name, $m['earned']];
            }
        }

        return collect($out);
    }

    private static function groupCols(string $first): array
    {
        return [[$first, 'text'], ['Bills', 'int'], ['Amount', 'money'], ['Paid', 'money'], ['Unpaid', 'money'], ['Share', 'pct']];
    }
}
