<?php

namespace Tests\Feature;

use App\Livewire\MasterLedgerPage;
use App\Models\CashCollection;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Staff;
use App\Models\User;
use App\Models\Vendor;
use App\Services\MasterLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class MasterLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $v;
    private Staff $s;
    private int $milk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 20:00:00');
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->milk = ExpenseCategory::where('name', 'Milk')->value('id');
        $this->v = Vendor::create(['name' => 'Gopal Dairy']);
        $this->s = Staff::create(['name' => 'Raju', 'joining_date' => '2026-10-01', 'salary_type' => 'monthly', 'salary_amount' => 9000]);

        CashCollection::create(['date' => '2026-10-31', 'cash' => 1000]);                       // before range
        CashCollection::create(['date' => '2026-11-02', 'cash' => 3000, 'upi' => 2000, 'online' => 500]);
        Expense::create(['date' => '2026-11-03', 'category_id' => $this->milk, 'vendor_id' => $this->v->id, 'amount' => 800, 'direct_paid' => 800, 'payment_mode' => 'cash']);
        Expense::create(['date' => '2026-11-04', 'category_id' => $this->milk, 'vendor_id' => $this->v->id, 'amount' => 1200, 'direct_paid' => 0]); // unpaid: not money
        $this->v->payments()->create(['date' => '2026-11-10', 'amount' => 700, 'payment_mode' => 'upi']);
        $this->s->payments()->createMany([
            ['date' => '2026-11-05', 'type' => 'salary', 'amount' => 4000, 'payment_mode' => 'cash'],
            ['date' => '2026-11-06', 'type' => 'deduction', 'amount' => 100], // not money
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function range(): array
    {
        return ['from' => '2026-11-01', 'to' => '2026-11-15'];
    }

    public function test_union_includes_only_real_money_movements(): void
    {
        $rows = MasterLedger::rows($this->range(), 0);
        $this->assertSame(
            ['sale-cash', 'sale-upi', 'sale-online', 'expense-cash', 'staff_payment-cash', 'vendor_payment-upi'],
            $rows->map(fn ($r) => $r['source'].'-'.$r['mode'])->all(),
        );
        $this->assertSame([3000.0, 5000.0, 5500.0, 4700.0, 700.0, 0.0], $rows->pluck('balance')->all());
    }

    public function test_opening_and_account_balances(): void
    {
        $this->assertSame(1000.0, MasterLedger::balanceBefore('2026-11-01'));
        $this->assertSame(['cash' => 1000.0 + 3000 - 800 - 4000, 'bank' => 2000.0 - 700, 'online' => 500.0], MasterLedger::accountBalances('2026-11-15'));

        $s = MasterLedger::summary($this->range());
        $this->assertSame(5500.0, $s['in']);
        $this->assertSame(5500.0, $s['out']);
        $this->assertSame(800.0, $s['sources']['expense']['out']);
        $this->assertSame(4000.0, $s['sources']['staff_payment']['out']);
    }

    public function test_filters(): void
    {
        $cnt = fn (array $f) => MasterLedger::rows($this->range() + $f, 0)->count();
        $this->assertSame(3, $cnt(['source' => 'sale']));
        $this->assertSame(3, $cnt(['direction' => 'out']));
        $this->assertSame(3, $cnt(['account' => 'cash']));
        $this->assertSame(2, $cnt(['account' => 'bank']));
        $this->assertSame(1, $cnt(['account' => 'online']));
        $this->assertSame(1000.0, MasterLedger::balanceBefore('2026-11-01', ['account' => 'cash']));
        $this->assertSame(0.0, MasterLedger::balanceBefore('2026-11-01', ['account' => 'bank']));
    }

    public function test_edits_and_deletes_flow_through_without_double_posting(): void
    {
        $c = CashCollection::whereDate('date', '2026-11-02')->first();
        $c->update(['cash' => 3500]);
        $c->update(['cash' => 3600]);
        $this->assertSame(6100.0, MasterLedger::summary($this->range())['in']);
        $this->assertSame(3, MasterLedger::rows($this->range() + ['source' => 'sale'], 0)->count());

        $c->delete();
        $this->assertSame(0.0, MasterLedger::summary($this->range())['in']);

        // Marking the unpaid bill paid today shows an outflow dated today.
        $e = Expense::where('direct_paid', 0)->first();
        $e->update(['direct_paid' => 1200, 'payment_mode' => 'upi', 'paid_on' => '2026-11-15']);
        $row = MasterLedger::rows($this->range() + ['source' => 'expense'], 0)->last();
        $this->assertSame('2026-11-15', $row['date']->toDateString());
        $this->assertSame(1200.0, $row['out']);
    }

    public function test_page_renders_filters_and_links(): void
    {
        $this->get('/ledger')->assertOk()->assertSee('Master Ledger')->assertSee('Gopal Dairy')->assertSee('Salary · Raju');

        Livewire::test(MasterLedgerPage::class)
            ->assertSet('from', '2026-11-01')->assertSet('to', '2026-11-15')
            ->assertViewHas('opening', 1000.0)
            ->assertViewHas('closing', 1000.0)
            ->set('source', 'staff_payment')->assertViewHas('rows', fn ($r) => $r->count() === 1)
            ->set('source', 'bogus')->assertSet('source', '')
            ->set('search', 'gopal')->assertViewHas('rows', fn ($r) => $r->count() === 2)
            ->call('clearFilters')->set('preset', 'last_month')
            ->assertSet('from', '2026-10-01')->assertViewHas('rows', fn ($r) => $r->count() === 1);
    }

    public function test_csv_export(): void
    {
        $csv = $this->get('/ledger/export?from=2026-11-01&to=2026-11-15')->assertOk()->streamedContent();
        $this->assertStringContainsString('Date,Type,Particulars', $csv);
        $this->assertStringContainsString(',Opening,"Opening balance",,,,,,1000', $csv);
        $this->assertStringContainsString('"Paid Gopal Dairy"', $csv);
        $this->assertStringContainsString('Total,,,,,5500,5500,1000', $csv);

        $this->get('/ledger/export?from=2026-11-10&to=2026-11-01')->assertSessionHasErrors('to');
        $this->get('/ledger/export?from=2026-11-01&to=2026-11-10&source=hack')->assertSessionHasErrors('source');
    }
}
