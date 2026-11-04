<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\CashCollection;
use App\Models\Expense;
use App\Models\Staff;
use App\Models\Vendor;
use App\Services\RevenueReport;
use App\Services\StaffLedger;

class DashboardController extends Controller
{
    /** GET /dashboard — everything the app's home screen shows, in one call. */
    public function __invoke()
    {
        $today = CashCollection::whereDate('date', today()->toDateString())->first();
        $month = CashCollection::totals(CashCollection::between(today()->startOfMonth(), today()));
        $exp = Expense::totals(Expense::between(today()->startOfMonth(), today()));
        $staff = Staff::active()->get();
        $balances = $staff->map(fn ($s) => StaffLedger::balance($s));

        return response()->json([
            'date' => today()->toDateString(),
            'today' => [
                'collection' => $today ? Fmt::collection($today) : null,
                'expenses' => Fmt::money(Expense::whereDate('date', today()->toDateString())->sum('amount')),
                'attendance_marked' => Attendance::whereDate('date', today()->toDateString())->count(),
                'active_staff' => $staff->count(),
            ],
            'month' => [
                'sales' => $month, 'expenses' => $exp,
                'profit' => Fmt::money($month['total'] - $exp['amount']),
            ],
            'owed_to_vendors' => Fmt::money(Vendor::query()->select('vendors.*')->withDue()->get()->sum(fn ($v) => max($v->due(), 0))),
            'salary_owed' => Fmt::money($balances->filter(fn ($b) => $b > 0)->sum()),
            'last_14_days' => (new RevenueReport(today()->subDays(13), today()))->dailySeries(),
            'by_category' => Expense::byCategory(Expense::between(today()->startOfMonth(), today())),
        ]);
    }
}
