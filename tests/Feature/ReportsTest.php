<?php

namespace Tests\Feature;

use App\Livewire\Reports;
use App\Models\Attendance;
use App\Models\CashCollection;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Staff;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 20:00:00');
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        $milk = ExpenseCategory::where('name', 'Milk')->value('id');
        $gas = ExpenseCategory::where('name', 'Gas')->value('id');
        $v = Vendor::create(['name' => 'Gopal Dairy', 'opening_balance' => 500]);

        CashCollection::create(['date' => '2026-10-01', 'cash' => 6000, 'upi' => 3000, 'online' => 1000]);
        CashCollection::create(['date' => '2026-10-02', 'cash' => 4000, 'upi' => 1000]);
        Expense::create(['date' => '2026-10-01', 'category_id' => $milk, 'vendor_id' => $v->id, 'amount' => 2000, 'direct_paid' => 0]);
        Expense::create(['date' => '2026-10-05', 'category_id' => $gas, 'amount' => 1000, 'direct_paid' => 1000, 'payment_mode' => 'cash']);
        $v->payments()->create(['date' => '2026-10-20', 'amount' => 1500, 'payment_mode' => 'upi']);

        // October has 31 days → 3100 / 31 = 100 per day; 1 absent → 3000
        $s = Staff::create(['name' => 'Raju', 'designation' => 'Cook', 'joining_date' => '2026-10-01', 'salary_type' => 'monthly', 'salary_amount' => 3100]);
        Attendance::create(['staff_id' => $s->id, 'date' => '2026-10-07', 'status' => 'A']);
        $s->payments()->create(['date' => '2026-10-15', 'type' => 'advance', 'amount' => 500, 'payment_mode' => 'cash']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function kpi(array $r, string $label): mixed
    {
        return collect($r['kpis'])->firstWhere(0, $label)[1];
    }

    public function test_monthly_profit_and_loss(): void
    {
        $r = ReportBuilder::monthly('2026-10');
        $this->assertSame(15000.0, $this->kpi($r, 'Sales'));
        $this->assertSame(3000.0, $this->kpi($r, 'Expenses'));     // includes the unpaid milk bill
        $this->assertSame(3000.0, $this->kpi($r, 'Staff cost'));   // 31 − 1 absent days × 100
        $this->assertSame(9000.0, $this->kpi($r, 'Net profit'));
        $this->assertSame(60.0, $this->kpi($r, 'Net margin'));
        $this->assertCount(2, $r['sections'][4]['rows']);          // daily sales
        // 1500 khata payment: 500 clears the opening balance first (FIFO), 1000 lands on the milk bill.
        $this->assertSame(['Milk', 1, 2000.0, 1000.0, 1000.0, 66.7], $r['sections'][2]['rows'][0]);
    }

    public function test_yearly_summary(): void
    {
        $r = ReportBuilder::yearly(2026);
        $this->assertSame('FY 2026-27 (Apr 2026 – Mar 2027)', $r['subtitle']);
        $this->assertCount(12, $r['sections'][0]['rows']);
        $this->assertSame(15000.0, $this->kpi($r, 'Sales'));
        // Nov staff cost: 15 of 30 days × (3100/30) = 1550
        $this->assertSame(3000.0 + 1550, $this->kpi($r, 'Staff cost'));
        $this->assertSame('Oct 2026', $this->kpi($r, 'Best month'));
    }

    public function test_sales_expenses_vendor_and_salary_reports(): void
    {
        $sales = ReportBuilder::sales('2026-10-01', '2026-10-31');
        $this->assertSame(7500.0, $this->kpi($sales, 'Average / day'));

        $exp = ReportBuilder::expenses('2026-10-01', '2026-10-31');
        $this->assertSame(1000.0, $this->kpi($exp, 'Unpaid'));
        $this->assertSame('(no vendor)', $exp['sections'][1]['rows'][1][0]);

        $dues = ReportBuilder::vendorDues();
        $this->assertSame(1000.0, $this->kpi($dues, 'Total owed')); // 500 + 2000 − 1500
        $this->assertSame('Gopal Dairy', $dues['sections'][0]['rows'][0][0]);

        $sal = ReportBuilder::salary('2026-10');
        $row = $sal['sections'][0]['rows'][0];
        $this->assertSame(['Raju', 'Cook', 'Monthly'], array_slice($row, 0, 3));
        $this->assertSame(1, $row[6]);          // absent
        $this->assertSame(3000.0, $row[10]);    // work value
        $this->assertSame(500.0, $row[15]);     // advance
        $this->assertSame(2500.0, $row[16]);    // closing
    }

    public function test_page_switches_reports(): void
    {
        $this->get('/reports')->assertOk()->assertSee('Monthly summary')->assertSee('Salary sheet');

        $c = Livewire::test(Reports::class)->assertSet('month', '2026-11');
        foreach (array_keys(ReportBuilder::TYPES) as $type) {
            $c->set('type', $type)->assertHasNoErrors()->assertViewHas('report', fn ($r) => $r['title'] === ReportBuilder::TYPES[$type][0]);
        }
        $c->set('type', 'nope')->assertSet('type', 'monthly')
            ->set('month', '2099-01')->assertSet('month', '2026-11')
            ->call('shiftMonth', -1)->assertSet('month', '2026-10')
            ->set('type', 'sales')->call('range', 'last_month')->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-31');
    }

    public function test_pdf_and_excel_downloads(): void
    {
        foreach ([
            'monthly' => '?month=2026-10', 'yearly' => '?fy=2026', 'sales' => '?from=2026-10-01&to=2026-10-31',
            'expenses' => '?from=2026-10-01&to=2026-10-31', 'vendor_dues' => '', 'salary' => '?month=2026-10',
        ] as $type => $qs) {
            $pdf = $this->get("/reports/{$type}.pdf{$qs}");
            $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
            $this->assertStringContainsString('attachment', $pdf->headers->get('content-disposition'));

            $xlsx = $this->get("/reports/{$type}.xlsx{$qs}");
            $xlsx->assertOk();
            $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('content-type'));
        }

        $this->assertStringContainsString('inline', $this->get('/reports/monthly.pdf?month=2026-10&view=1')->headers->get('content-disposition'));
    }

    public function test_excel_contains_real_numbers(): void
    {
        $res = $this->get('/reports/monthly.xlsx?month=2026-10');
        $path = tempnam(sys_get_temp_dir(), 'rp').'.xlsx';
        file_put_contents($path, $res->getFile()->getContent());

        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $this->assertSame(['Summary', 'Profit & loss', 'Sales by payment mode', 'Expenses by category', 'Staff cost', 'Daily sales'], $book->getSheetNames());
        $pl = $book->getSheetByName('Profit & loss');
        $this->assertSame('Sales', $pl->getCell('A2')->getValue());
        $this->assertEquals(15000, $pl->getCell('B2')->getValue());   // number, not "₹15,000" text
        $this->assertEquals(0.6, $pl->getCell('C6')->getValue());     // 60% net margin stored as 0.6
        @unlink($path);
    }

    public function test_download_validation(): void
    {
        $this->get('/reports/monthly.pdf?month=2026-13')->assertSessionHasErrors('month');
        $this->get('/reports/sales.xlsx?from=2026-10-10&to=2026-10-01')->assertSessionHasErrors('to');
        $this->get('/reports/hacker.pdf')->assertNotFound();
        $this->get('/reports/monthly.exe?month=2026-10')->assertNotFound();

        auth()->logout();
        $this->get('/reports/vendor_dues.pdf')->assertRedirect('/login');
    }
}
