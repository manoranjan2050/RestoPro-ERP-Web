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
        $this->seedSales();
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

    /**
     * v2 sample shop: menu with sizes, tables, customers, two weeks of POS bills, a running table, a delivery and
     * advance cake orders. DEMO_MODE=advanced also switches the app to Advanced mode.
     */
    private function seedSales(): void
    {
        if (\App\Models\Item::count() > 0) {
            return;
        }
        $user = \App\Models\User::orderBy('id')->first();
        if (! $user) {
            return;
        }
        $menu = [
            'Cakes' => ['#ec4899', [
                ['Black Forest', 'egg', [['500 g', 450], ['1 kg', 850], ['2 kg', 1650]]],
                ['Pineapple Cake', 'egg', [['500 g', 400], ['1 kg', 780]]],
                ['Chocolate Truffle', 'egg', [['500 g', 550], ['1 kg', 1050]]],
                ['Eggless Butterscotch', 'veg', [['500 g', 480], ['1 kg', 920]]],
            ]],
            'Pastries' => ['#f59e0b', [['Chocolate Pastry', 'egg', 70], ['Red Velvet Pastry', 'egg', 90], ['Black Forest Pastry', 'egg', 65], ['Blueberry Cheesecake', 'egg', 120]]],
            'Snacks' => ['#10b981', [['Veg Puff', 'veg', 25], ['Paneer Roll', 'veg', 60], ['Chicken Patties', 'nonveg', 45], ['Veg Sandwich', 'veg', 70]]],
            'Beverages' => ['#0ea5e9', [['Masala Tea', 'veg', 20], ['Cold Coffee', 'veg', 90], ['Fresh Lime Soda', 'veg', 60], ['Hot Chocolate', 'veg', 110]]],
        ];
        $all = [];
        $sort = 0;
        foreach ($menu as $cat => [$color, $items]) {
            $c = \App\Models\ItemCategory::create(['name' => $cat, 'color' => $color, 'sort' => $sort++]);
            foreach ($items as [$name, $food, $price]) {
                $item = \App\Models\Item::create([
                    'category_id' => $c->id, 'name' => $name, 'food_type' => $food, 'gst_rate' => 5,
                    'hsn' => in_array($cat, ['Cakes', 'Pastries'], true) ? '1905' : '2106',
                    'price' => is_array($price) ? $price[0][1] : $price,
                    'track_stock' => in_array($cat, ['Cakes', 'Pastries'], true), 'low_stock_at' => 3,
                    'code' => strtoupper(implode('', array_map(fn ($w) => $w[0], explode(' ', $name)))).$c->id,
                ]);
                foreach (is_array($price) ? $price : [] as $i => [$vn, $vp]) {
                    $item->variants()->create(['name' => $vn, 'price' => $vp, 'sort' => $i]);
                }
                $all[] = $item->load('variants');
            }
        }
        foreach (['T1', 'T2', 'T3', 'T4', 'T5', 'T6'] as $i => $t) {
            \App\Models\DiningTable::create(['name' => $t, 'area' => $i < 4 ? 'Ground floor' : 'Terrace', 'seats' => $i % 2 ? 2 : 4, 'sort' => $i]);
        }
        $people = [['Priya Das', '9000000101'], ['Rahul Sahoo', '9000000102'], ['Meena Patra', '9000000103'], ['Arjun Rath', '9000000104'], ['Sneha Mishra', '9000000105']];
        foreach ($people as $i => [$n, $m]) {
            \App\Models\Customer::create(['name' => $n, 'mobile' => $m, 'birthday' => today()->subYears(25 + $i)->addDays($i * 2)->toDateString(), 'address' => ($i + 3).' Station Road']);
        }

        $wasMode = \App\Models\Setting::get('app_mode', 'simple');
        \App\Models\Setting::put('app_mode', 'advanced'); // Billing reads the mode for GST / stock
        foreach ($all as $item) {
            if ($item->track_stock) {
                foreach ($item->variants->isNotEmpty() ? $item->variants : [null] as $v) {
                    \App\Services\Stock::move($item, $v, mt_rand(8, 20), 'opening', $user);
                }
            }
        }
        $pick = function () use ($all) {
            $item = $all[array_rand($all)];
            $v = $item->variants->isNotEmpty() ? $item->variants->random() : null;

            return ['item_id' => $item->id, 'variant_id' => $v?->id, 'qty' => $v ? 1 : mt_rand(1, 3), 'note' => ''];
        };
        $base = today(); // fixed before faking the clock (today() follows Carbon::setTestNow)
        for ($d = 13; $d >= 0; $d--) {
            $n = mt_rand(6, 12);
            for ($b = 0; $b < $n; $b++) {
                \Illuminate\Support\Carbon::setTestNow($base->copy()->subDays($d)->setTime(mt_rand(9, 20), mt_rand(0, 59)));
                $cart = array_map(fn () => $pick(), range(1, mt_rand(1, 3)));
                $total = \App\Services\Billing::totals(\App\Services\Billing::price($cart), 0, 'amt')['total'];
                $who = mt_rand(0, 2) ? null : $people[array_rand($people)];
                \App\Services\Billing::save($cart, ['type' => mt_rand(0, 3) ? 'counter' : 'takeaway', 'customer_name' => $who[0] ?? null, 'customer_mobile' => $who[1] ?? null],
                    [['mode' => ['cash', 'upi', 'upi', 'card'][mt_rand(0, 3)], 'amount' => $total]], $user);
            }
        }
        \Illuminate\Support\Carbon::setTestNow();
        // this morning's baking
        foreach ($all as $item) {
            if ($item->track_stock) {
                foreach ($item->variants->isNotEmpty() ? $item->variants : [null] as $v) {
                    \App\Services\Stock::move($item, $v, mt_rand(6, 14), 'made', $user);
                }
            }
        }

        $by = fn (string $name) => collect($all)->firstWhere('name', $name);
        $size = fn (string $name, string $v) => $by($name)->variants->firstWhere('name', $v)->id;
        $o = \App\Services\Billing::save([['item_id' => $by('Masala Tea')->id, 'qty' => 3], ['item_id' => $by('Paneer Roll')->id, 'qty' => 2]],
            ['mode' => 'open', 'type' => 'dine_in', 'table_id' => \App\Models\DiningTable::where('name', 'T2')->value('id'), 'guests' => 3], [], $user);
        \App\Services\Billing::sendKot($o);
        \App\Services\Billing::save([['item_id' => $by('Veg Puff')->id, 'qty' => 6], ['item_id' => $by('Cold Coffee')->id, 'qty' => 2]],
            ['mode' => 'open', 'type' => 'delivery', 'customer_name' => 'Rahul Sahoo', 'customer_mobile' => '9000000102', 'delivery_address' => '4 Station Road', 'delivery_charge' => 30], [], $user);
        \App\Services\Billing::save([['item_id' => $by('Black Forest')->id, 'variant_id' => $size('Black Forest', '1 kg'), 'qty' => 1]],
            ['mode' => 'open', 'type' => 'advance', 'due_at' => today()->addDay()->setTime(17, 30), 'customer_name' => 'Meena Patra', 'customer_mobile' => '9000000103',
                'details' => ['flavour' => 'Black Forest', 'weight' => '1 kg', 'message' => 'Happy Birthday Riya', 'eggless' => true]], [['mode' => 'upi', 'amount' => 400]], $user);
        \App\Services\Billing::save([['item_id' => $by('Chocolate Truffle')->id, 'variant_id' => $size('Chocolate Truffle', '1 kg'), 'qty' => 2]],
            ['mode' => 'open', 'type' => 'advance', 'due_at' => today()->setTime(19, 0), 'customer_name' => 'Sneha Mishra', 'customer_mobile' => '9000000105',
                'details' => ['shape' => 'Heart', 'message' => 'Happy Anniversary']], [['mode' => 'cash', 'amount' => 1000]], $user);
        \App\Models\Setting::put('app_mode', env('DEMO_MODE') === 'advanced' ? 'advanced' : $wasMode);
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
