<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CashCollection;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Staff;
use App\Models\StaffPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-time import of the old "Resto Pro Enterprise" PHP app's SQL backup (RESTO_FULL_*.sql).
 *
 * Imported: transactions (daily sales), expense_categories + expense_details, staff_list,
 * staff_attendance, staff_ledger. Skipped: admin_users, users, global_settings, telegram_logs.
 *
 * Mapping decisions (see the report the command prints):
 *  - sales: cash_sales → cash, online_sales → upi (or online with --online=online); several rows on one date are added up.
 *  - expenses: all old bills were "Paid" → paid in full on the bill date, mode cash (UPI when the note says online/PhonePe).
 *    Zero-amount bills are skipped. Old category names are matched to the new ones (GAS → Gas, VEGETABLES → Vegetable …).
 *  - staff: joining date = first attendance/payment; a staff member whose attendance and payments stop more than
 *    60 days before the last date in the backup is marked as left on their last attendance day (otherwise unmarked days
 *    after that would keep earning salary). Attendance Present/Half-Day/Absent/Leave → P/H/A/L.
 *  - staff_ledger "Advance" → advance, "Salary_Payment" → salary.
 */
class LegacyImport
{
    private const CATEGORY_ALIASES = [
        'gas' => 'Gas', 'electricity' => 'Electricity', 'milk' => 'Milk', 'chena/paneer' => 'Chena/Paneer',
        'koila' => 'Koila', 'vegetables' => 'Vegetable', 'vegetable' => 'Vegetable', 'grocery' => 'Grocery',
        'other' => 'Other', 'pan/tea/safal/extra' => 'PAN/TEA/EXTRA', 'pan/tea/extra' => 'PAN/TEA/EXTRA',
    ];

    private const ATTENDANCE = ['Present' => 'P', 'Half-Day' => 'H', 'Absent' => 'A', 'Leave' => 'L'];

    /** @var array<string, list<list<?string>>> */
    public array $tables = [];

    public array $report = [];

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("File not found: {$path}");
        }
        $sql = file_get_contents($path);
        if (! str_contains(substr($sql, 0, 2000), 'RESTO PRO') || ! str_contains($sql, 'CREATE TABLE `transactions`')) {
            throw new RuntimeException('This does not look like an old Resto Pro backup (no RESTO PRO header / transactions table).');
        }
        $self = new self;
        $self->tables = self::parse($sql);

        return $self;
    }

    /** Parse `INSERT INTO `t` VALUES(...);` lines (one row per statement, as the old app writes them). */
    public static function parse(string $sql): array
    {
        $rows = [];
        preg_match_all('/^INSERT INTO `(\w+)` VALUES\((.*)\);\s*$/m', $sql, $m, PREG_SET_ORDER);
        foreach ($m as [, $table, $body]) {
            $rows[$table][] = self::values($body);
        }

        return $rows;
    }

    private static function values(string $b): array
    {
        $out = [];
        $n = strlen($b);
        $i = 0;
        while ($i < $n) {
            $c = $b[$i];
            if ($c === "'") {
                $s = '';
                $j = $i + 1;
                while ($j < $n) {
                    $ch = $b[$j];
                    if ($ch === '\\' && $j + 1 < $n) {
                        $nx = $b[$j + 1];
                        $s .= match ($nx) { 'n' => "\n", 'r' => "\r", 't' => "\t", '0' => '', default => $nx };
                        $j += 2;

                        continue;
                    }
                    if ($ch === "'") {
                        if ($j + 1 < $n && $b[$j + 1] === "'") {
                            $s .= "'";
                            $j += 2;

                            continue;
                        }
                        break;
                    }
                    $s .= $ch;
                    $j++;
                }
                $out[] = $s;
                $i = $j + 1;
            } elseif (substr($b, $i, 4) === 'NULL') {
                $out[] = null;
                $i += 4;
            } elseif ($c === ',' || $c === ' ') {
                $i++;
            } else {
                $j = strcspn($b, ',', $i);
                $out[] = trim(substr($b, $i, $j));
                $i += $j;
            }
        }

        return $out;
    }

    private function rows(string $table): array
    {
        return $this->tables[$table] ?? [];
    }

    private static function note(?string $s, int $max = 255): ?string
    {
        $s = trim(preg_replace('/\s*[\r\n]+\s*/', ', ', (string) $s));

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private static function looksUpi(?string $note): bool
    {
        return (bool) preg_match('/online|phone\s*pe|phonepe|prone\s*pe|upi|gpay|paytm/i', (string) $note);
    }

    /** Things already in the new database that the import would collide with. */
    public static function conflicts(): array
    {
        return array_filter([
            'sales days' => CashCollection::count(),
            'expenses' => Expense::count(),
            'staff' => Staff::count(),
        ]);
    }

    /** Build everything in memory first (used for the dry run and for the real import). */
    public function plan(string $onlineMode = 'upi'): array
    {
        // ---- sales
        $sales = [];
        $mergedDays = [];
        foreach ($this->rows('transactions') as $r) {
            [, $date, $cash, $online] = $r;
            $note = self::note($r[8] ?? null);
            if (isset($sales[$date])) {
                $mergedDays[] = $date;
                $sales[$date]['cash'] += (float) $cash;
                $sales[$date][$onlineMode] += (float) $online;
                $sales[$date]['note'] = self::note(implode(', ', array_filter([$sales[$date]['note'], $note])));
            } else {
                $sales[$date] = ['date' => $date, 'cash' => (float) $cash, 'upi' => 0.0, 'online' => 0.0, 'note' => $note];
                $sales[$date][$onlineMode] = (float) $online;
            }
        }
        ksort($sales);

        // ---- expenses
        $oldCats = [];
        foreach ($this->rows('expense_categories') as $r) {
            $key = mb_strtolower(trim($r[1]));
            $oldCats[$r[0]] = self::CATEGORY_ALIASES[$key] ?? mb_convert_case(trim($r[1]), MB_CASE_TITLE);
        }
        $expenses = [];
        $skippedZero = 0;
        foreach ($this->rows('expense_details') as $r) {
            [, $date, $catId, $amount, $note, $status] = $r + [5 => 'Paid'];
            if ((float) $amount <= 0) {
                $skippedZero++;

                continue;
            }
            $note = self::note($note);
            $paid = $status !== 'Pending';
            $expenses[] = [
                'date' => $date, 'category' => $oldCats[$catId] ?? 'Other', 'amount' => (float) $amount,
                'direct_paid' => $paid ? (float) $amount : 0, 'payment_mode' => $paid ? (self::looksUpi($note) ? 'upi' : 'cash') : null,
                'paid_on' => $paid ? $date : null, 'note' => $note,
            ];
        }

        // ---- staff
        $lastDate = max(array_merge(array_keys($sales), array_column($expenses, 'date'), ['1970-01-01']));
        $staff = [];
        foreach ($this->rows('staff_list') as $r) {
            [$id, $name, $mobile, $address, $created, $salary] = $r;
            $att = array_filter($this->rows('staff_attendance'), fn ($a) => $a[1] === $id && $a[2]);
            $pay = array_filter($this->rows('staff_ledger'), fn ($p) => $p[1] === $id);
            $dates = array_merge(array_column($att, 2), array_column($pay, 4));
            $first = $dates ? min($dates) : substr((string) $created, 0, 10);
            $lastSeen = $dates ? max($dates) : null;
            $lastAtt = $att ? max(array_column($att, 2)) : $lastSeen;
            $left = $lastSeen && (strtotime($lastDate) - strtotime($lastSeen)) > 60 * 86400;
            $staff[$id] = [
                'row' => [
                    'name' => trim($name), 'mobile' => preg_replace('/\D/', '', (string) $mobile) ?: null,
                    'address' => self::note($address), 'joining_date' => $first,
                    'leaving_date' => $left ? $lastAtt : null, 'is_active' => ! $left,
                    'salary_type' => 'monthly', 'salary_amount' => (float) $salary, 'opening_balance' => 0,
                    'note' => 'Imported from old system',
                ],
                'attendance' => array_values(array_map(fn ($a) => [
                    'date' => $a[2], 'status' => self::ATTENDANCE[$a[3]] ?? 'P', 'note' => self::note($a[4] ?? null, 120),
                ], $att)),
                'payments' => array_values(array_map(fn ($p) => [
                    'date' => $p[4], 'type' => $p[2] === 'Salary_Payment' ? 'salary' : 'advance', 'amount' => (float) $p[3],
                    'payment_mode' => self::looksUpi($p[5] ?? null) ? 'upi' : 'cash', 'note' => self::note($p[5] ?? null),
                ], $pay)),
            ];
        }

        $this->report = [
            'sales_days' => count($sales), 'sales_rows_old' => count($this->rows('transactions')), 'merged_days' => $mergedDays,
            'sales_cash' => array_sum(array_column($sales, 'cash')), 'sales_upi' => array_sum(array_column($sales, 'upi')),
            'sales_online' => array_sum(array_column($sales, 'online')),
            'expenses' => count($expenses), 'expenses_old' => count($this->rows('expense_details')), 'skipped_zero' => $skippedZero,
            'expense_total' => array_sum(array_column($expenses, 'amount')),
            'by_category' => collect($expenses)->groupBy('category')->map->sum('amount')->sortKeys()->all(),
            'staff' => collect($staff)->map(fn ($s) => [
                'name' => $s['row']['name'], 'salary' => $s['row']['salary_amount'], 'joined' => $s['row']['joining_date'],
                'left' => $s['row']['leaving_date'], 'attendance' => count($s['attendance']),
                'att_by_status' => collect($s['attendance'])->countBy('status')->all(),
                'payments' => count($s['payments']), 'paid' => array_sum(array_column($s['payments'], 'amount')),
            ])->values()->all(),
            'range' => [min(array_keys($sales) ?: [null]), $lastDate],
        ];

        return compact('sales', 'expenses', 'staff');
    }

    /** Write everything in one transaction. */
    public function run(string $onlineMode = 'upi'): array
    {
        $plan = $this->plan($onlineMode);
        $by = User::where('role', User::ROLE_OWNER)->orderBy('id')->value('id');

        DB::transaction(function () use ($plan, $by) {
            foreach ($plan['sales'] as $s) {
                CashCollection::create($s + ['created_by' => $by, 'updated_by' => $by]);
            }

            $cats = [];
            foreach (array_unique(array_column($plan['expenses'], 'category')) as $name) {
                $cat = ExpenseCategory::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                    ?? ExpenseCategory::create([
                        'name' => $name, 'color' => ExpenseCategory::PALETTE[ExpenseCategory::count() % count(ExpenseCategory::PALETTE)],
                        'sort' => (int) ExpenseCategory::max('sort') + 1, 'is_active' => true,
                    ]);
                $cats[$name] = $cat->id;
            }
            foreach ($plan['expenses'] as $e) {
                $x = new Expense(['category_id' => $cats[$e['category']]] + collect($e)->except('category')->all());
                $x->created_by = $by;
                $x->updated_by = $by;
                $x->save();
            }

            foreach ($plan['staff'] as $s) {
                $staff = Staff::create($s['row']);
                foreach ($s['attendance'] as $a) {
                    Attendance::create($a + ['staff_id' => $staff->id]);
                }
                foreach ($s['payments'] as $p) {
                    StaffPayment::create($p + ['staff_id' => $staff->id, 'created_by' => $by, 'updated_by' => $by]);
                }
            }
        });

        return $this->report;
    }
}
