<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\CashCollection;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Staff;
use App\Models\StaffPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private function dump(): string
    {
        $sql = <<<'SQL'
-- RESTO PRO ENTERPRISE BACKUP
CREATE TABLE `expense_categories` (
INSERT INTO `expense_categories` VALUES('1','GAS',NULL,'0.00');
INSERT INTO `expense_categories` VALUES('6','VEGETABLES',NULL,'0.00');
INSERT INTO `expense_categories` VALUES('9','Fish',NULL,'0.00');
CREATE TABLE `expense_details` (
INSERT INTO `expense_details` VALUES('1','2026-01-02','1','900.00','2 cylinder\r\nfrom jhulu','Paid');
INSERT INTO `expense_details` VALUES('2','2026-01-02','6','0.00','','Paid');
INSERT INTO `expense_details` VALUES('3','2026-01-03','9','300.00','online','Paid');
CREATE TABLE `staff_list` (
INSERT INTO `staff_list` VALUES('1','RAMU','9876543210','O\'Hara lane','2026-01-01 10:00:00','15000.00');
CREATE TABLE `staff_attendance` (
INSERT INTO `staff_attendance` VALUES('1','1','2026-01-01','Present',NULL);
INSERT INTO `staff_attendance` VALUES('2','1','2026-01-02','Half-Day',NULL);
INSERT INTO `staff_attendance` VALUES('3','1','2026-01-03','Leave',NULL);
CREATE TABLE `staff_ledger` (
INSERT INTO `staff_ledger` VALUES('1','1','Advance','500.00','2026-01-02','via phonepe');
CREATE TABLE `transactions` (
INSERT INTO `transactions` VALUES('1','2026-01-01','1000.00','500.00','0.00','0.00',NULL,'0.00','open');
INSERT INTO `transactions` VALUES('2','2026-01-01','200.00','50.00','0.00','0.00',NULL,'0.00','');
INSERT INTO `transactions` VALUES('3','2026-01-03','700.00','0.00','0.00','0.00',NULL,'0.00','');
SQL;
        $path = tempnam(sys_get_temp_dir(), 'resto');
        file_put_contents($path, $sql);

        return $path;
    }

    public function test_imports_sales_expenses_and_staff(): void
    {
        $this->artisan('restopro:import-old', ['file' => $this->dump()])->assertSuccessful();

        $this->assertSame(2, CashCollection::count());
        $day = CashCollection::where('date', '2026-01-01')->first();
        $this->assertEquals([1200, 550, 0, 1750], [(float) $day->cash, (float) $day->upi, (float) $day->online, (float) $day->total]);

        $this->assertSame(2, Expense::count()); // zero-amount bill skipped
        $gas = Expense::where('amount', 900)->first();
        $this->assertSame('Gas', $gas->category->name); // matched to the existing category
        $this->assertSame(['paid', 'cash', '2 cylinder, from jhulu'], [$gas->status, $gas->payment_mode, $gas->note]);
        $this->assertSame('upi', Expense::where('amount', 300)->value('payment_mode'));
        $this->assertTrue(ExpenseCategory::where('name', 'Fish')->exists()); // unknown category created

        $staff = Staff::sole();
        $this->assertSame(['RAMU', "O'Hara lane", '2026-01-01'], [$staff->name, $staff->address, $staff->joining_date->toDateString()]);
        $this->assertSame(['P', 'H', 'L'], Attendance::orderBy('date')->pluck('status')->all());
        $p = StaffPayment::sole();
        $this->assertSame(['advance', 'upi', 500.0], [$p->type, $p->payment_mode, (float) $p->amount]);
    }

    public function test_dry_run_writes_nothing_and_second_import_is_refused(): void
    {
        $file = $this->dump();
        $this->artisan('restopro:import-old', ['file' => $file, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, CashCollection::count());

        $this->artisan('restopro:import-old', ['file' => $file])->assertSuccessful();
        $this->artisan('restopro:import-old', ['file' => $file])->assertFailed();
        $this->assertSame(2, CashCollection::count());
    }

    public function test_online_sales_can_go_to_online(): void
    {
        $this->artisan('restopro:import-old', ['file' => $this->dump(), '--online' => 'online'])->assertSuccessful();
        $this->assertEquals(550, (float) CashCollection::where('date', '2026-01-01')->value('online'));
    }
}
