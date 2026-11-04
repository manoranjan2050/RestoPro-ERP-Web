<?php

namespace Tests\Feature;

use App\Livewire\Expenses;
use App\Livewire\VendorKhata;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\VendorLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class VendorKhataTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $v;
    private int $milk;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-15 20:00:00');
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        $this->milk = ExpenseCategory::where('name', 'Milk')->value('id');
        $this->v = Vendor::create(['name' => 'Gopal Dairy', 'mobile' => '9876500000', 'opening_balance' => 500]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bill(string $date, float $amount, float $direct = 0): Expense
    {
        return Expense::create(['date' => $date, 'category_id' => $this->milk, 'vendor_id' => $this->v->id,
            'amount' => $amount, 'direct_paid' => $direct, 'payment_mode' => $direct ? 'cash' : null]);
    }

    private function pay(float $amount, string $date = '2026-11-14'): VendorPayment
    {
        return $this->v->payments()->create(['date' => $date, 'amount' => $amount, 'payment_mode' => 'upi']);
    }

    private function statuses(): array
    {
        return Expense::orderBy('date')->orderBy('id')->get()->map(fn ($e) => $e->status.':'.$e->paid_amount)->all();
    }

    public function test_balance_formula(): void
    {
        $this->bill('2026-11-01', 1000);
        $this->bill('2026-11-02', 800, 800);   // paid at purchase: no effect on balance
        $this->bill('2026-11-03', 600, 200);   // part paid at purchase
        $this->assertSame(1900.0, VendorLedger::balance($this->v)); // 500 + 1000 + 0 + 400
        $this->pay(700);
        $this->assertSame(1200.0, $this->v->fresh()->due());
        $this->assertSame(1200.0, Vendor::select('vendors.*')->withDue()->find($this->v->id)->due());
    }

    public function test_fifo_settles_opening_then_oldest_bills(): void
    {
        $this->bill('2026-11-01', 1000);
        $this->bill('2026-11-05', 600, 200);
        $this->bill('2026-11-09', 300);

        $this->pay(900); // 500 opening + 400 of first bill
        $this->assertSame(['partial:400.00', 'partial:200.00', 'unpaid:0.00'], $this->statuses());

        $this->pay(1000); // 600 rest of bill 1 + 400 rest of bill 2
        $this->assertSame(['paid:1000.00', 'paid:600.00', 'unpaid:0.00'], $this->statuses());

        $alloc = \App\Models\VendorPaymentAllocation::whereNull('expense_id')->sum('amount');
        $this->assertEquals(500, $alloc);
    }

    public function test_overpayment_becomes_advance_and_applies_to_new_bills(): void
    {
        $this->bill('2026-11-01', 300);
        $this->pay(1000);
        $this->assertSame(-200.0, $this->v->fresh()->due());

        $b = $this->bill('2026-11-10', 150);
        $this->assertSame('paid', $b->fresh()->status);
        $this->assertSame(-50.0, $this->v->fresh()->due());
    }

    public function test_editing_and_deleting_payments_and_bills_rebalances(): void
    {
        $b1 = $this->bill('2026-11-01', 1000);
        $b2 = $this->bill('2026-11-02', 500);
        $p = $this->pay(2000);
        $this->assertSame(['paid:1000.00', 'paid:500.00'], $this->statuses());

        $p->update(['amount' => 1000]);
        $this->assertSame(['partial:500.00', 'unpaid:0.00'], $this->statuses());

        $b1->delete(); // payment flows on to the next bill
        $this->assertSame(['paid:500.00'], $this->statuses());

        $p->delete();
        $this->assertSame(['unpaid:0.00'], $this->statuses());
        $this->assertSame(1000.0, $this->v->fresh()->due());

        $this->v->update(['opening_balance' => 0]);
        $this->pay(200);
        $this->assertSame(['partial:200.00'], $this->statuses());
    }

    public function test_moving_bill_to_another_vendor_rebalances_both(): void
    {
        $other = Vendor::create(['name' => 'Other']);
        $b = $this->bill('2026-11-01', 400);
        $this->v->update(['opening_balance' => 0]);
        $this->pay(400);
        $this->assertSame('paid', $b->fresh()->status);

        $b->update(['vendor_id' => $other->id]);
        $this->assertSame('unpaid', $b->fresh()->status);
        $this->assertSame(-400.0, $this->v->fresh()->due()); // payment is now an advance
        $this->assertSame(400.0, $other->fresh()->due());
    }

    public function test_expense_form_keeps_khata_allocation_when_editing(): void
    {
        $this->v->update(['opening_balance' => 0]);
        $b = $this->bill('2026-11-01', 1000);
        $this->pay(300);

        Livewire::test(Expenses::class)->call('edit', $b->id)
            ->assertSet('status', 'unpaid')->assertSet('khataPaid', 300.0)
            ->set('note', 'edited')->call('save')->assertHasNoErrors();
        $this->assertSame('partial:300.00', $this->statuses()[0]);

        // Mark paid settles only the remaining 700 on the bill itself.
        Livewire::test(Expenses::class)->call('markPaid', $b->id, 'cash');
        $b->refresh();
        $this->assertSame('700.00', $b->direct_paid);
        $this->assertSame('paid', $b->status);
        $this->assertSame(0.0, $this->v->fresh()->due()); // 700 on the bill + 300 khata = settled, no double count
    }

    public function test_khata_page_records_edits_and_deletes_payments(): void
    {
        $this->bill('2026-11-01', 1000);

        $this->get(route('vendors.khata', $this->v))->assertOk()->assertSee('You owe')->assertSee('₹1,500');

        Livewire::test(VendorKhata::class, ['vendor' => $this->v])
            ->call('pay')->assertSet('amount', '1500')
            ->set('amount', '600')->set('payment_mode', 'upi')->set('note', 'weekly')
            ->call('save')->assertHasNoErrors()
            ->assertViewHas('balance', 900.0);

        $p = VendorPayment::sole();
        Livewire::test(VendorKhata::class, ['vendor' => $this->v])
            ->call('edit', $p->id)->set('amount', '1500')->call('save')
            ->assertViewHas('balance', 0.0);

        Livewire::test(VendorKhata::class, ['vendor' => $this->v])
            ->call('pay')->set('amount', '0')->call('save')->assertHasErrors('amount')
            ->set('amount', '10')->set('date', '2026-12-01')->call('save')->assertHasErrors('date');

        Livewire::test(VendorKhata::class, ['vendor' => $this->v])
            ->call('confirmDelete', $p->id)->call('delete')
            ->assertViewHas('balance', 1500.0);
    }

    public function test_statement_running_balance_and_range(): void
    {
        $this->bill('2026-10-20', 1000);
        $this->pay(300, '2026-10-25');
        $this->bill('2026-11-02', 400, 100);
        $this->pay(500, '2026-11-10');

        $all = VendorLedger::statement($this->v);
        $this->assertSame(500.0, $all['opening']);
        $this->assertSame([1500.0, 1200.0, 1500.0, 1000.0], array_column($all['rows'], 'balance'));
        $this->assertSame(1000.0, $all['closing']);

        $nov = VendorLedger::statement($this->v, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertSame(1200.0, $nov['opening']); // brought forward
        $this->assertCount(2, $nov['rows']);
        $this->assertSame(1000.0, $nov['closing']);
    }

    public function test_whatsapp_link_and_manager_cannot_delete_payment(): void
    {
        $this->bill('2026-11-01', 1000);
        Livewire::test(VendorKhata::class, ['vendor' => $this->v])
            ->assertViewHas('waLink', fn ($l) => str_starts_with($l, 'https://wa.me/919876500000?text=') && str_contains(urldecode($l), '₹1,500'));

        $p = $this->pay(100);
        $this->actingAs(User::factory()->create(['role' => 'manager']));
        Livewire::test(VendorKhata::class, ['vendor' => $this->v])->call('confirmDelete', $p->id)->assertForbidden();
    }

    public function test_vendor_with_payments_is_deactivated_not_deleted(): void
    {
        $this->pay(100);
        Livewire::test(\App\Livewire\Vendors::class)->call('confirmDelete', $this->v->id)->call('delete');
        $this->assertFalse($this->v->fresh()->is_active);
    }
}
