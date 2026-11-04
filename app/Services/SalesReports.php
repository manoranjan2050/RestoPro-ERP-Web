<?php

namespace App\Services;

use App\Models\CashCollection;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\StockMovement;
use App\Support\Sales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * v2 (Advanced mode) reports from POS bills, in the same shape as ReportBuilder so the preview, PDF and Excel
 * templates render them unchanged. "Sales" here = paid bills by bill date (cancelled excluded); "collected" = money
 * received that day by payment date (includes advances taken for later orders).
 */
class SalesReports
{
    public const TYPES = [
        'day_end' => ['Day-end report', 'Bills, money by mode, cancellations — print at closing', 'day'],
        'item_sales' => ['Item-wise sales', 'What sold, how many, by item and category', 'range'],
        'hourly' => ['Sales by hour', 'Busy hours, weekdays and order types', 'range'],
        'gst_summary' => ['GST summary', 'Tax by rate, HSN summary and B2B bills for your CA', 'range'],
        'bills' => ['Bill register', 'Every bill with discount, tax and status', 'range'],
    ];

    public static function build(string $type, array $p): array
    {
        return match ($type) {
            'day_end' => self::dayEnd($p['date']),
            'item_sales' => self::items($p['from'], $p['to']),
            'hourly' => self::hourly($p['from'], $p['to']),
            'gst_summary' => self::gst($p['from'], $p['to']),
            'bills' => self::bills($p['from'], $p['to']),
        };
    }

    // ------------------------------------------------------------------ helpers

    private static function paid(string $from, string $to)
    {
        return Order::where('status', 'completed')
            ->where('billed_at', '>=', Carbon::parse($from)->startOfDay())
            ->where('billed_at', '<=', Carbon::parse($to)->endOfDay());
    }

    private static function range(string $from, string $to): string
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);

        return $f->isSameDay($t) ? $f->format('d M Y') : $f->format('d M Y').' – '.$t->format('d M Y');
    }

    private static function pct(float $v, float $of): float
    {
        return $of > 0 ? round($v / $of * 100, 1) : 0;
    }

    /** Lines of paid bills, grouped by item + size. */
    private static function itemRows(string $from, string $to): Collection
    {
        return OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('items', 'items.id', '=', 'order_items.item_id')
            ->leftJoin('item_categories', 'item_categories.id', '=', 'items.category_id')
            ->where('orders.status', 'completed')
            ->where('orders.billed_at', '>=', Carbon::parse($from)->startOfDay())
            ->where('orders.billed_at', '<=', Carbon::parse($to)->endOfDay())
            ->groupBy('order_items.name', 'order_items.variant_name', 'item_categories.name', 'order_items.unit')
            ->selectRaw('order_items.name, order_items.variant_name, item_categories.name as category, order_items.unit,
                SUM(order_items.qty) as qty, SUM(order_items.amount) as gross, SUM(order_items.discount) as disc, SUM(order_items.total) as net,
                COUNT(DISTINCT order_items.order_id) as bills')
            ->get();
    }

    // ------------------------------------------------------------------ day end

    public static function dayEnd(string $date): array
    {
        $d = Carbon::parse($date)->toDateString();
        $bills = self::paid($d, $d)->with('items')->get();
        $net = (float) $bills->sum('total');
        $tax = (float) $bills->sum('cgst') + (float) $bills->sum('sgst');
        $cancelled = Order::where('status', 'cancelled')->whereDate('cancelled_at', $d)->get();

        $pay = OrderPayment::query()->join('orders', 'orders.id', '=', 'order_payments.order_id')
            ->where('orders.status', '!=', 'cancelled')->whereDate('order_payments.date', $d)
            ->groupBy('order_payments.mode')->selectRaw('order_payments.mode, SUM(order_payments.amount) as s, COUNT(*) as n')->get()->keyBy('mode');
        $collected = (float) $pay->sum('s');
        $advanceToday = (float) OrderPayment::query()->join('orders', 'orders.id', '=', 'order_payments.order_id')
            ->where('orders.status', 'open')->whereDate('order_payments.date', $d)->sum('order_payments.amount');

        $cc = CashCollection::whereDate('date', $d)->first();
        $manual = $cc ? DaySales::manual($cc) : ['cash' => 0, 'upi' => 0, 'online' => 0];

        $sections = [
            [
                'title' => 'Money received today (by payment date)',
                'columns' => [['Mode', 'text'], ['Payments', 'int'], ['Amount', 'money']],
                'rows' => collect(OrderPayment::MODES)->map(fn ($m, $k) => [$m[0], (int) ($pay[$k]->n ?? 0), (float) ($pay[$k]->s ?? 0)])->values()->all(),
                'totals' => ['Total from bills', (int) $pay->sum('n'), $collected],
            ],
            [
                'title' => 'Bills by type',
                'columns' => [['Type', 'text'], ['Bills', 'int'], ['Sales', 'money']],
                'rows' => $bills->groupBy('type')->map(fn ($g, $t) => [Order::TYPES[$t] ?? $t, $g->count(), (float) $g->sum('total')])->values()->all(),
                'totals' => ['Total', $bills->count(), $net],
            ],
            [
                'title' => 'Top items',
                'columns' => [['Item', 'text'], ['Qty', 'num'], ['Sales', 'money']],
                'rows' => self::itemRows($d, $d)->sortByDesc('net')->take(10)
                    ->map(fn ($r) => [$r->name.($r->variant_name ? ' ('.$r->variant_name.')' : ''), (float) $r->qty, (float) $r->net])->values()->all(),
                'totals' => null,
            ],
        ];
        if ($cancelled->isNotEmpty()) {
            $sections[] = [
                'title' => 'Cancelled today',
                'columns' => [['Bill', 'text'], ['Amount', 'money'], ['Reason', 'text']],
                'rows' => $cancelled->map(fn ($o) => [$o->ref(), (float) $o->total, (string) $o->cancel_reason])->all(),
                'totals' => ['Total', (float) $cancelled->sum('total'), ''],
            ];
        }
        if (array_sum($manual) > 0) {
            $sections[] = [
                'title' => 'Sales typed in Cash Collection (not billed)',
                'columns' => [['Mode', 'text'], ['Amount', 'money']],
                'rows' => [['Cash', $manual['cash']], ['UPI', $manual['upi']], ['Online', $manual['online']]],
                'totals' => ['Total', array_sum($manual)],
            ];
        }
        if (Sales::on('advance')) {
            $due = Order::where('type', 'advance')->whereIn('status', ['open', 'completed'])->where('stage', '!=', 'delivered')
                ->whereDate('due_at', Carbon::parse($d)->addDay())->orderBy('due_at')->get();
            if ($due->isNotEmpty()) {
                $sections[] = [
                    'title' => 'Advance orders due tomorrow',
                    'columns' => [['Time', 'text'], ['Customer', 'text'], ['Balance', 'money']],
                    'rows' => $due->map(fn ($o) => [$o->due_at->format('h:i A'), $o->customer_name.' · '.$o->customer_mobile, $o->balance()])->all(),
                    'totals' => null,
                ];
            }
        }
        if (Sales::on('stock')) {
            $waste = StockMovement::with(['item', 'variant'])->whereDate('date', $d)->where('reason', 'wastage')->get();
            if ($waste->isNotEmpty()) {
                $sections[] = [
                    'title' => 'Wastage today',
                    'columns' => [['Item', 'text'], ['Qty', 'num'], ['Note', 'text']],
                    'rows' => $waste->map(fn ($m) => [$m->item?->name.($m->variant ? ' ('.$m->variant->name.')' : ''), -(float) $m->qty, (string) $m->note])->all(),
                    'totals' => null,
                ];
            }
        }

        return [
            'title' => 'Day-end report',
            'subtitle' => Carbon::parse($d)->format('l, d M Y'),
            'kpis' => [
                ['Bills', $bills->count(), 'int'],
                ['Sales (paid bills)', $net, 'money'],
                ['Collected today', $collected, 'money'],
                ['Discount given', (float) $bills->sum('discount'), 'money'],
                ['GST', $tax, 'money'],
                ['Average bill', $bills->count() ? round($net / $bills->count(), 2) : 0, 'money'],
            ],
            'sections' => $sections,
            'notes' => array_values(array_filter([
                'Sales = bills paid today. Collected = money received today, including advances for orders not yet handed over.',
                $advanceToday > 0 ? 'Advances taken today for later orders: '.money($advanceToday).'.' : null,
                $cancelled->isNotEmpty() ? $cancelled->count().' bill(s) cancelled today — their money is not counted.' : null,
            ])),
        ];
    }

    // ------------------------------------------------------------------ items

    public static function items(string $from, string $to): array
    {
        $rows = self::itemRows($from, $to);
        $net = (float) $rows->sum('net');
        $bills = self::paid($from, $to)->count();
        $byCat = $rows->groupBy(fn ($r) => $r->category ?? 'No category')
            ->map(fn ($g, $c) => [$c, (float) $g->sum('qty'), (float) $g->sum('net'), self::pct((float) $g->sum('net'), $net)])
            ->sortByDesc(fn ($r) => $r[2])->values()->all();

        return [
            'title' => 'Item-wise sales',
            'subtitle' => self::range($from, $to),
            'kpis' => [
                ['Bills', $bills, 'int'],
                ['Items sold', (float) $rows->sum('qty'), 'num'],
                ['Net sales', $net, 'money'],
                ['Different items', $rows->count(), 'int'],
            ],
            'sections' => [
                [
                    'title' => 'By item',
                    'columns' => [['Item', 'text'], ['Size', 'text'], ['Category', 'text'], ['Qty', 'num'], ['Bills', 'int'], ['Gross', 'money'], ['Discount', 'money'], ['Net', 'money'], ['Share', 'pct']],
                    'rows' => $rows->sortByDesc('net')->map(fn ($r) => [
                        $r->name, (string) $r->variant_name, (string) ($r->category ?? '—'), (float) $r->qty, (int) $r->bills,
                        (float) $r->gross, (float) $r->disc, (float) $r->net, self::pct((float) $r->net, $net),
                    ])->values()->all(),
                    'totals' => ['Total', '', '', (float) $rows->sum('qty'), $bills, (float) $rows->sum('gross'), (float) $rows->sum('disc'), $net, 100],
                ],
                [
                    'title' => 'By category',
                    'columns' => [['Category', 'text'], ['Qty', 'num'], ['Net', 'money'], ['Share', 'pct']],
                    'rows' => $byCat,
                    'totals' => ['Total', (float) $rows->sum('qty'), $net, 100],
                ],
            ],
            'notes' => ['Net = after the bill discount, including GST where charged. Cancelled bills are left out.'],
        ];
    }

    // ------------------------------------------------------------------ hourly

    public static function hourly(string $from, string $to): array
    {
        $bills = self::paid($from, $to)->get(['id', 'type', 'total', 'billed_at']);
        $total = (float) $bills->sum('total');
        $byHour = $bills->groupBy(fn ($o) => (int) $o->billed_at->format('G'))->sortKeys();
        $days = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
        $byDow = $bills->groupBy(fn ($o) => $o->billed_at->dayOfWeekIso)->sortKeys();
        $busiest = $byHour->sortByDesc(fn ($g) => $g->sum('total'))->keys()->first();

        return [
            'title' => 'Sales by hour',
            'subtitle' => self::range($from, $to),
            'kpis' => [
                ['Bills', $bills->count(), 'int'],
                ['Sales', $total, 'money'],
                ['Busiest hour', $busiest === null ? '—' : Carbon::createFromTime($busiest)->format('g A').'–'.Carbon::createFromTime(($busiest + 1) % 24)->format('g A'), 'text'],
                ['Bills per day', round($bills->count() / $days, 1), 'num'],
            ],
            'sections' => [
                [
                    'title' => 'By hour of day',
                    'columns' => [['Hour', 'text'], ['Bills', 'int'], ['Sales', 'money'], ['Average bill', 'money'], ['Share', 'pct']],
                    'rows' => $byHour->map(fn ($g, $h) => [
                        Carbon::createFromTime($h)->format('g A').' – '.Carbon::createFromTime(($h + 1) % 24)->format('g A'),
                        $g->count(), (float) $g->sum('total'), round($g->sum('total') / $g->count(), 2), self::pct((float) $g->sum('total'), $total),
                    ])->values()->all(),
                    'totals' => ['Total', $bills->count(), $total, $bills->count() ? round($total / $bills->count(), 2) : 0, 100],
                ],
                [
                    'title' => 'By day of week',
                    'columns' => [['Day', 'text'], ['Bills', 'int'], ['Sales', 'money'], ['Share', 'pct']],
                    'rows' => $byDow->map(fn ($g, $d) => [Carbon::now()->startOfWeek()->addDays($d - 1)->format('l'), $g->count(), (float) $g->sum('total'), self::pct((float) $g->sum('total'), $total)])->values()->all(),
                    'totals' => null,
                ],
                [
                    'title' => 'By order type',
                    'columns' => [['Type', 'text'], ['Bills', 'int'], ['Sales', 'money'], ['Share', 'pct']],
                    'rows' => $bills->groupBy('type')->map(fn ($g, $t) => [Order::TYPES[$t] ?? $t, $g->count(), (float) $g->sum('total'), self::pct((float) $g->sum('total'), $total)])->values()->all(),
                    'totals' => null,
                ],
            ],
            'notes' => ['Times are when the bill was paid.'],
        ];
    }

    // ------------------------------------------------------------------ GST

    public static function gst(string $from, string $to): array
    {
        $q = fn () => OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'completed')->where('orders.gst_enabled', true)
            ->where('orders.billed_at', '>=', Carbon::parse($from)->startOfDay())
            ->where('orders.billed_at', '<=', Carbon::parse($to)->endOfDay());
        $byRate = $q()->groupBy('order_items.gst_rate')->orderBy('order_items.gst_rate')
            ->selectRaw('order_items.gst_rate as rate, SUM(order_items.taxable) as taxable, SUM(order_items.tax) as tax, SUM(order_items.total) as value')->get();
        $byHsn = $q()->groupBy('order_items.hsn', 'order_items.gst_rate')->orderBy('order_items.hsn')
            ->selectRaw('order_items.hsn, order_items.gst_rate as rate, SUM(order_items.qty) as qty, SUM(order_items.taxable) as taxable, SUM(order_items.tax) as tax')->get();
        $b2b = self::paid($from, $to)->where('gst_enabled', true)->whereNotNull('customer_gstin')->orderBy('billed_at')->get();
        $bills = self::paid($from, $to)->where('gst_enabled', true);
        $taxable = (float) $byRate->sum('taxable');
        $tax = (float) $byRate->sum('tax');
        $split = fn (float $t) => [round($t / 2, 2), round($t - round($t / 2, 2), 2)];

        return [
            'title' => 'GST summary',
            'subtitle' => self::range($from, $to),
            'kpis' => [
                ['Tax invoices', (clone $bills)->count(), 'int'],
                ['Taxable value', $taxable, 'money'],
                ['CGST', $split($tax)[0], 'money'],
                ['SGST', $split($tax)[1], 'money'],
                ['Total tax', $tax, 'money'],
                ['Invoice value', (float) $byRate->sum('value'), 'money'],
            ],
            'sections' => [
                [
                    'title' => 'By GST rate',
                    'columns' => [['Rate', 'text'], ['Taxable value', 'money'], ['CGST', 'money'], ['SGST', 'money'], ['Total tax', 'money'], ['Invoice value', 'money']],
                    'rows' => $byRate->map(fn ($r) => [(float) $r->rate.'%', (float) $r->taxable, ...$split((float) $r->tax), (float) $r->tax, (float) $r->value])->all(),
                    'totals' => ['Total', $taxable, ...$split($tax), $tax, (float) $byRate->sum('value')],
                ],
                [
                    'title' => 'HSN summary',
                    'columns' => [['HSN', 'text'], ['Rate', 'text'], ['Qty', 'num'], ['Taxable value', 'money'], ['Tax', 'money']],
                    'rows' => $byHsn->map(fn ($r) => [$r->hsn ?: '(none)', (float) $r->rate.'%', (float) $r->qty, (float) $r->taxable, (float) $r->tax])->all(),
                    'totals' => ['Total', '', (float) $byHsn->sum('qty'), $taxable, $tax],
                ],
                [
                    'title' => 'B2B bills (customer has GSTIN)',
                    'columns' => [['Date', 'date'], ['Bill', 'text'], ['Customer', 'text'], ['GSTIN', 'text'], ['Taxable', 'money'], ['Tax', 'money'], ['Total', 'money']],
                    'rows' => $b2b->map(fn ($o) => [$o->billed_at->toDateString(), $o->number, (string) $o->customer_name, $o->customer_gstin, (float) $o->taxable, (float) $o->cgst + (float) $o->sgst, (float) $o->total])->all(),
                    'totals' => $b2b->isNotEmpty() ? ['Total', '', '', '', (float) $b2b->sum('taxable'), (float) $b2b->sum('cgst') + (float) $b2b->sum('sgst'), (float) $b2b->sum('total')] : null,
                ],
            ],
            'notes' => [
                'Only bills made while GST billing was on. Sales inside the state: tax split equally into CGST and SGST.',
                'Use this as a working summary for GSTR-1 / GSTR-3B — your CA should check it before filing.',
            ],
        ];
    }

    // ------------------------------------------------------------------ bill register

    public static function bills(string $from, string $to): array
    {
        $all = Order::with('payments')->whereNotNull('number')
            ->where('billed_at', '>=', Carbon::parse($from)->startOfDay())->where('billed_at', '<=', Carbon::parse($to)->endOfDay())
            ->withCount('items')->orderBy('billed_at')->get();
        $ok = $all->where('status', '!=', 'cancelled');
        $cancelled = $all->where('status', 'cancelled');
        $disc = $ok->filter(fn ($o) => (float) $o->discount > 0);
        $modes = fn ($o) => $o->payments->groupBy('mode')->keys()->map(fn ($m) => OrderPayment::MODES[$m][0] ?? $m)->implode(' + ');

        return [
            'title' => 'Bill register',
            'subtitle' => self::range($from, $to),
            'kpis' => [
                ['Bills', $ok->count(), 'int'],
                ['Sales', (float) $ok->sum('total'), 'money'],
                ['Discounts', (float) $ok->sum('discount'), 'money'],
                ['Cancelled', $cancelled->count(), 'int'],
            ],
            'sections' => [
                [
                    'title' => 'Bills',
                    'columns' => [['Date', 'date'], ['Bill', 'text'], ['Type', 'text'], ['Customer', 'text'], ['Items', 'int'], ['Discount', 'money'], ['Tax', 'money'], ['Total', 'money'], ['Paid by', 'text'], ['Status', 'text']],
                    'rows' => $all->map(fn ($o) => [
                        $o->billed_at->toDateString(), $o->number, Order::TYPES[$o->type] ?? $o->type, (string) $o->customer_name, $o->items_count,
                        (float) $o->discount, (float) $o->cgst + (float) $o->sgst, (float) $o->total, $modes($o), Order::STATUSES[$o->status] ?? $o->status,
                    ])->values()->all(),
                    'totals' => ['Total (not cancelled)', '', '', '', (int) $ok->sum('items_count'), (float) $ok->sum('discount'), (float) $ok->sum('cgst') + (float) $ok->sum('sgst'), (float) $ok->sum('total'), '', ''],
                ],
                [
                    'title' => 'Discounts given',
                    'columns' => [['Bill', 'text'], ['Subtotal', 'money'], ['Discount', 'money'], ['%', 'pct']],
                    'rows' => $disc->map(fn ($o) => [$o->number, (float) $o->subtotal, (float) $o->discount, self::pct((float) $o->discount, (float) $o->subtotal)])->values()->all(),
                    'totals' => $disc->isNotEmpty() ? ['Total', (float) $disc->sum('subtotal'), (float) $disc->sum('discount'), self::pct((float) $disc->sum('discount'), (float) $disc->sum('subtotal'))] : null,
                ],
                [
                    'title' => 'Cancelled bills',
                    'columns' => [['Bill', 'text'], ['Total', 'money'], ['Cancelled on', 'date'], ['Reason', 'text']],
                    'rows' => $cancelled->map(fn ($o) => [$o->number, (float) $o->total, $o->cancelled_at?->toDateString() ?? '', (string) $o->cancel_reason])->values()->all(),
                    'totals' => null,
                ],
            ],
            'notes' => ['Bill numbers run without gaps; cancelled bills keep their number and are listed separately.'],
        ];
    }
}
