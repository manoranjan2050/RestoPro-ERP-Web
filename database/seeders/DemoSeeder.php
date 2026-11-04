<?php

namespace Database\Seeders;

use App\Models\CashCollection;
use Illuminate\Database\Seeder;

/**
 * Local demo data only: `php artisan db:seed --class=DemoSeeder`.
 * DEMO_DAYS=400 in the environment seeds a longer history (default 45 days).
 * Never run in production (refuses to).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder refuses to run in production.');

            return;
        }

        mt_srand(42);
        $days = max(10, (int) env('DEMO_DAYS', 45));
        for ($i = $days; $i >= 1; $i--) {
            $d = today()->subDays($i);
            if (mt_rand(1, 12) === 1) {
                continue; // a few gaps, like a closed day
            }
            $weekend = $d->isWeekend() ? 1.4 : 1.0;
            CashCollection::updateOrCreate(['date' => $d->toDateString()], [
                'cash' => round(mt_rand(2500, 6500) * $weekend, -1),
                'upi' => round(mt_rand(2000, 5500) * $weekend, -1),
                'online' => round(mt_rand(0, 2500) * $weekend, -1),
            ]);
        }

        // Vendors + expenses
        $cat = \App\Models\ExpenseCategory::pluck('id', 'name');
        $vendors = [
            ['Gopal Dairy', 'Milk', 1500], ['Sharma Vegetables', 'Vegetable', 0], ['Indane Gas Agency', 'Gas', 0],
            ['Maa Tara Paneer', 'Chena/Paneer', 800], ['Kirana Store', 'Grocery', 0],
        ];
        $vid = [];
        foreach ($vendors as [$name, $c, $ob]) {
            $vid[$c] = \App\Models\Vendor::updateOrCreate(['name' => $name], ['default_category_id' => $cat[$c], 'opening_balance' => $ob])->id;
        }
        $this->seedStaff($days);
        if (\App\Models\Expense::count() > 0) {
            return;
        }
        for ($i = $days; $i >= 0; $i--) {
            $d = today()->subDays($i)->toDateString();
            $daily = [['Milk', 700, 1100], ['Vegetable', 400, 900], ['Chena/Paneer', 500, 1400], ['PAN/TEA/EXTRA', 80, 250]];
            if ($i % 4 === 0) $daily[] = ['Grocery', 900, 2600];
            if ($i % 9 === 0) $daily[] = ['Gas', 1800, 1900];
            if ($i % 6 === 0) $daily[] = ['Koila', 300, 600];
            if ($i % 30 === 20) $daily[] = ['Electricity', 4200, 4200];
            foreach ($daily as [$c, $min, $max]) {
                $amount = round(mt_rand($min, $max), -1);
                $unpaid = in_array($c, ['Milk', 'Chena/Paneer'], true) && $i < 10 && mt_rand(0, 1);
                \App\Models\Expense::create([
                    'date' => $d, 'category_id' => $cat[$c], 'vendor_id' => $vid[$c] ?? null, 'amount' => $amount,
                    'direct_paid' => $unpaid ? 0 : $amount, 'payment_mode' => $unpaid ? null : (mt_rand(0, 1) ? 'cash' : 'upi'),
                ]);
            }
        }
    }

    private function seedStaff(int $days): void
    {
        if (\App\Models\Staff::count() > 0) {
            return;
        }
        $people = [
            ['Ramesh Sahu', 'Head Cook', 'monthly', 15000, max(60, $days)],
            ['Bapi Nayak', 'Helper', 'monthly', 8000, max(60, $days)],
            ['Sunita Behera', 'Cleaner', 'daily', 350, max(60, $days)],
            ['Arjun Das', 'Waiter', 'monthly', 9000, 25],
        ];
        foreach ($people as [$name, $role, $type, $rate, $daysAgo]) {
            $s = \App\Models\Staff::create([
                'name' => $name, 'designation' => $role, 'salary_type' => $type, 'salary_amount' => $rate,
                'joining_date' => today()->subDays($daysAgo)->toDateString(), 'mobile' => '90000000'.str_pad((string) (\App\Models\Staff::count() + 1), 2, '0', STR_PAD_LEFT), // obviously fake
            ]);
            for ($i = $daysAgo; $i >= 1; $i--) {
                $r = mt_rand(1, 20);
                $status = $r === 1 ? 'A' : ($r === 2 ? 'H' : (today()->subDays($i)->isTuesday() ? 'O' : 'P'));
                \App\Models\Attendance::create(['staff_id' => $s->id, 'date' => today()->subDays($i)->toDateString(), 'status' => $status]);
            }
            // Older months: salary paid in full early next month, so the ledger carries forward cleanly.
            for ($m = today()->subDays($daysAgo)->startOfMonth()->addMonth(); $m->lt(today()->subMonthNoOverflow()->startOfMonth()); $m->addMonth()) {
                $s->payments()->create(['date' => $m->copy()->addMonth()->addDays(2)->toDateString(), 'type' => 'salary',
                    'amount' => \App\Services\StaffLedger::month($s, $m->format('Y-m'))['work_value'], 'payment_mode' => mt_rand(0, 1) ? 'cash' : 'upi', 'for_month' => $m->format('Y-m')]);
            }
            if ($daysAgo > 40) {
                $last = today()->subMonthNoOverflow();
                $s->payments()->create(['date' => $last->copy()->startOfMonth()->addDays(14)->toDateString(), 'type' => 'advance', 'amount' => 2000, 'payment_mode' => 'cash']);
                $s->payments()->create(['date' => today()->startOfMonth()->addDays(2)->toDateString(), 'type' => 'salary',
                    'amount' => $type === 'daily' ? 6000 : $rate - 2000, 'payment_mode' => 'upi', 'for_month' => $last->format('Y-m')]);
            }
        }
    }
}
