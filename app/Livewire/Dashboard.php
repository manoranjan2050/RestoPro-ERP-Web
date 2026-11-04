<?php

namespace App\Livewire;

use App\Models\CashCollection;
use App\Models\Expense;
use App\Services\RevenueReport;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    public function mount()
    {
        // Cashiers have no back office: send them to the counter.
        if (auth()->user()->isCashier()) {
            return $this->redirectRoute(auth()->user()->homeRoute(), navigate: true);
        }
    }

    public function render()
    {
        $today = CashCollection::whereDate('date', today()->toDateString())->first();
        $month = CashCollection::totals(CashCollection::between(today()->startOfMonth(), today()));
        $lastMonth = CashCollection::totals(CashCollection::between(
            today()->subMonthNoOverflow()->startOfMonth(),
            today()->subMonthNoOverflow(), // same day-of-month for a fair comparison
        ));
        $growth = $lastMonth['total'] > 0 ? ($month['total'] - $lastMonth['total']) / $lastMonth['total'] * 100 : null;

        $trend = (new RevenueReport(today()->subDays(13), today()))->dailySeries();
        $recent = CashCollection::orderByDesc('date')->limit(5)->get();

        $expQuery = Expense::between(today()->startOfMonth(), today());
        $exp = Expense::totals($expQuery);
        $monthExpenses = $exp['amount'];
        $byCategory = Expense::byCategory($expQuery);
        $todayExpenses = (float) Expense::whereDate('date', today()->toDateString())->sum('amount');
        // Owed to vendors (khata balances; advances don't offset other vendors' dues).
        $allDue = \App\Models\Vendor::query()->select('vendors.*')->withDue()->get()->sum(fn ($v) => max($v->due(), 0));
        $profit = $month['total'] - $monthExpenses;

        $stats = [
            ['label' => "Today's Sales", 'value' => money($today?->total ?? 0), 'icon' => 'cash', 'tone' => 'emerald',
                'hint' => $today ? 'Cash '.money($today->cash).' · UPI '.money($today->upi) : 'Not entered yet'],
            ['label' => 'This Month Sales', 'value' => money($month['total']), 'icon' => 'trending', 'tone' => 'sky',
                'hint' => $growth === null ? now()->format('F Y') : (($growth >= 0 ? '▲ ' : '▼ ').abs(round($growth)).'% vs same days last month')],
            ['label' => 'This Month Expenses', 'value' => money($monthExpenses), 'icon' => 'receipt', 'tone' => 'rose',
                'hint' => $exp['unpaid'] > 0 ? money($exp['unpaid']).' unpaid · today '.money($todayExpenses) : 'Today '.money($todayExpenses)],
            ['label' => 'Net Profit', 'value' => money($profit), 'icon' => 'spark', 'tone' => $profit < 0 ? 'rose' : 'violet',
                'hint' => $month['total'] > 0 ? 'Margin '.round($profit / $month['total'] * 100).'% · sales − expenses' : 'Sales − expenses'],
        ];

        return view('livewire.dashboard', compact('stats', 'trend', 'recent', 'today', 'month', 'byCategory', 'allDue') + ['pos' => $this->posWidgets()]);
    }

    /** Advanced mode: what's happening at the counter today. Null in Simple mode. */
    private function posWidgets(): ?array
    {
        if (! \App\Support\Sales::advanced()) {
            return null;
        }
        $bills = \App\Models\Order::where('status', 'completed')->whereDate('billed_at', today());
        $count = (clone $bills)->count();
        $sum = (float) (clone $bills)->sum('total');
        $top = \App\Models\OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', 'completed')->whereDate('orders.billed_at', today())
            ->groupBy('order_items.name', 'order_items.variant_name')
            ->selectRaw('order_items.name, order_items.variant_name, SUM(order_items.qty) as qty, SUM(order_items.total) as net')
            ->orderByDesc('net')->limit(5)->get();
        $advance = \App\Support\Sales::on('advance')
            ? \App\Models\Order::where('type', 'advance')->whereIn('status', ['open', 'completed'])->where('stage', '!=', 'delivered')
                ->where('due_at', '<', today()->addDays(2))->orderBy('due_at')->limit(6)->get()
            : collect();

        return [
            'bills' => $count, 'sales' => $sum, 'avg' => $count ? $sum / $count : 0,
            'tables' => \App\Support\Sales::on('tables') ? \App\Models\Order::where('type', 'dine_in')->where('status', 'open')->count() : null,
            'deliveries' => \App\Support\Sales::on('delivery') ? \App\Models\Order::where('type', 'delivery')->where('status', '!=', 'cancelled')
                ->where(fn ($q) => $q->where('stage', '!=', 'delivered')->orWhere('status', 'open'))->count() : null,
            'top' => $top,
            'advance' => $advance,
            'low' => \App\Support\Sales::on('stock') ? collect(\App\Services\Stock::rows())->filter(fn ($r) => $r['active'] && ($r['low'] || $r['qty'] <= 0))->take(6)->values() : collect(),
        ];
    }
}
