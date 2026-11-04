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

        return view('livewire.dashboard', compact('stats', 'trend', 'recent', 'today', 'month', 'byCategory', 'allDue'));
    }
}
