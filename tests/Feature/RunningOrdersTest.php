<?php

namespace Tests\Feature;

use App\Livewire\Sales\Orders;
use App\Livewire\Sales\Pos;
use App\Livewire\Sales\Tables;
use App\Models\CashCollection;
use App\Models\DiningTable;
use App\Models\Item;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RunningOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Item $tea;
    private Item $cake;
    private DiningTable $t1;
    private DiningTable $t2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        Setting::put('app_mode', 'advanced');
        $this->tea = Item::create(['name' => 'Masala Tea', 'price' => 20]);
        $this->cake = Item::create(['name' => 'Truffle Cake', 'price' => 900]);
        $this->t1 = DiningTable::create(['name' => 'T1', 'seats' => 4]);
        $this->t2 = DiningTable::create(['name' => 'T2', 'seats' => 2]);
    }

    private function line(Item $i, float $qty = 1, ?int $kot = null): array
    {
        return ['item_id' => $i->id, 'variant_id' => null, 'qty' => $qty, 'note' => '', 'kot_no' => $kot];
    }

    // ------------------------------------------------------------------ dine-in

    public function test_table_bill_with_two_kot_rounds_then_settle(): void
    {
        $page = Livewire::actingAs($this->owner)->test(Pos::class, ['openTable' => $this->t1->id])
            ->assertSet('type', 'dine_in')->assertSet('table_id', $this->t1->id)
            ->call('add', $this->tea->id)->call('add', $this->tea->id)
            ->call('sendKot')->assertHasNoErrors()->assertDispatched('print-kot');

        $o = Order::sole();
        $this->assertSame(['open', 'dine_in', $this->t1->id, null], [$o->status, $o->type, $o->table_id, $o->number]);
        $this->assertSame([1], $o->items()->pluck('kot_no')->all());
        $this->assertSame(0, CashCollection::count()); // nothing paid yet

        // second round: new cake line gets KOT 2, tea keeps KOT 1
        $page->call('add', $this->cake->id)->call('sendKot');
        $this->assertEqualsCanonicalizing([1, 2], $o->items()->pluck('kot_no')->all());
        $this->actingAs($this->owner)->get(route('orders.kot', $o).'?kot=2')->assertSee('Truffle Cake')->assertDontSee('Masala Tea')->assertSee('KOT 2');

        // table shows as running; the same table can't get a second bill
        $this->get(route('tables'))->assertOk()->assertSee('running')->assertSee('940');
        try {
            Billing::save([$this->line($this->tea)], ['mode' => 'open', 'type' => 'dine_in', 'table_id' => $this->t1->id], [], $this->owner);
            $this->fail('table should be busy');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('table_id', $e->errors());
        }

        // reopen from the table and settle
        Livewire::actingAs($this->owner)->test(Pos::class, ['openTable' => $this->t1->id])
            ->assertSet('orderId', $o->id)->assertCount('cart', 2)
            ->call('openPay')->assertSet('payments.0.amount', '940')->call('complete')->assertHasNoErrors();
        $o->refresh();
        $this->assertSame('completed', $o->status);
        $this->assertNotNull($o->number);
        $this->assertEquals(940, (float) CashCollection::sole()->cash);
        $this->assertNull($this->t1->fresh()->openOrder);
    }

    public function test_dine_in_needs_a_table_and_can_move(): void
    {
        Livewire::actingAs($this->owner)->test(Pos::class)->call('setType', 'dine_in')
            ->call('add', $this->tea->id)->call('sendKot')->assertHasErrors('table_id');

        $o = Billing::save([$this->line($this->tea)], ['mode' => 'open', 'type' => 'dine_in', 'table_id' => $this->t1->id], [], $this->owner);
        Livewire::actingAs($this->owner)->test(Tables::class)->call('startMove', $o->id)->set('moveTo', (string) $this->t2->id)->call('move')->assertHasNoErrors();
        $this->assertSame($this->t2->id, $o->fresh()->table_id);
    }

    public function test_tables_page_quick_add_and_feature_switch(): void
    {
        Livewire::actingAs($this->owner)->test(Tables::class)
            ->set('bulk', ['prefix' => 'T', 'from' => '1', 'to' => '5', 'area' => '', 'seats' => '4'])->call('saveBulk')->assertHasNoErrors();
        $this->assertSame(5, DiningTable::count()); // T1, T2 existed → T3..T5 added

        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier)->get(route('tables'))->assertOk();
        Livewire::actingAs($cashier)->test(Tables::class)->call('create')->assertForbidden();

        Setting::put('sales_tables', '0');
        $this->actingAs($this->owner)->get(route('tables'))->assertNotFound();
    }

    // ------------------------------------------------------------------ delivery

    public function test_delivery_order_moves_through_the_board(): void
    {
        Setting::put('delivery_charge', '30');
        $page = Livewire::actingAs($this->owner)->test(Pos::class)->call('setType', 'delivery')->assertSet('delivery_charge', '30')
            ->call('add', $this->cake->id)->set('customer_mobile', '9000000022')->set('customer_name', 'Ravi')
            ->call('openPay', 'open')->assertSet('payMode', 'open')
            ->call('complete')->assertHasErrors('delivery_address');

        $page->set('delivery_address', '12 MG Road')->call('openPay', 'open')->set('payments.0.amount', '')->call('complete')->assertHasNoErrors();
        $o = Order::sole();
        $this->assertSame(['open', 'placed', 930.0, 0.0], [$o->status, $o->stage, (float) $o->total, (float) $o->paid]);
        $this->assertSame('12 MG Road', $o->customer->address);

        $board = Livewire::actingAs($this->owner)->test(Orders::class)->set('tab', 'delivery')->assertSee('12 MG Road')->assertSee('Collect');
        foreach (['preparing', 'ready', 'out', 'delivered'] as $s) {
            $board->call('advance', $o->id);
            $this->assertSame($s, $o->fresh()->stage);
        }
        // delivered but not paid → still on the board until settled
        $board->assertSee($o->ref());
        Billing::save([$this->line($this->cake)], ['mode' => 'complete', 'type' => 'delivery', 'customer_mobile' => '9000000022', 'customer_name' => 'Ravi',
            'delivery_address' => '12 MG Road', 'delivery_charge' => 30], [['mode' => 'cash', 'amount' => 930]], $this->owner, $o->fresh());
        Livewire::actingAs($this->owner)->test(Orders::class)->set('tab', 'delivery')->assertDontSee('12 MG Road');
    }

    // ------------------------------------------------------------------ advance

    public function test_advance_cake_order_with_part_payment(): void
    {
        $due = today()->addDay()->toDateString();
        Livewire::actingAs($this->owner)->test(Pos::class)->call('setType', 'advance')
            ->call('add', $this->cake->id)
            ->set('customer_mobile', '9000000033')->set('customer_name', 'Meena')
            ->set('due_date', $due)->set('due_time', '17:30')
            ->set('details.flavour', 'Chocolate truffle')->set('details.message', 'Happy Birthday Riya')->set('details.eggless', true)
            ->call('openPay', 'open')->set('payments.0.amount', '1000')->call('complete')->assertHasErrors('payments') // more than total
            ->set('payments.0.amount', '400')->set('payments.0.mode', 'upi')->call('complete')->assertHasNoErrors();

        $o = Order::sole();
        $this->assertSame(['open', 'booked', 400.0, 500.0], [$o->status, $o->stage, (float) $o->paid, $o->balance()]);
        $this->assertSame($due.' 17:30', $o->due_at->format('Y-m-d H:i'));
        $this->assertSame(['flavour' => 'Chocolate truffle', 'message' => 'Happy Birthday Riya', 'eggless' => true], $o->details);
        $this->assertEquals(400, (float) CashCollection::whereDate('date', today())->value('upi')); // advance counts today

        // advance receipt + board
        $this->actingAs($this->owner)->get(route('orders.print', $o))->assertOk()->assertSee('ADVANCE RECEIPT')->assertSee('Happy Birthday Riya')->assertSee('Balance due');
        Livewire::actingAs($this->owner)->test(Orders::class)->set('tab', 'advance')->assertSee('Tomorrow')->assertSee('Meena')->assertSee('Eggless');
        $this->assertStringContainsString('Pickup', rawurldecode(\App\Services\BillView::whatsappUrl($o)));

        // pickup: collect the balance
        Livewire::actingAs($this->owner)->test(Pos::class, ['openOrder' => $o->id])
            ->assertSet('type', 'advance')->assertSet('details.flavour', 'Chocolate truffle')
            ->call('openPay')->assertSet('payments.0.amount', '500')->call('complete')->assertHasNoErrors();
        $o->refresh();
        $this->assertSame(['completed', 900.0], [$o->status, (float) $o->paid]);
        $this->assertNotNull($o->number);
    }

    public function test_advance_needs_date_and_customer_and_cancel_refunds_sales(): void
    {
        try {
            Billing::save([$this->line($this->cake)], ['mode' => 'open', 'type' => 'advance'], [], $this->owner);
            $this->fail('expected validation');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('due_at', $e->errors());
        }

        $o = Billing::save([$this->line($this->cake)], ['mode' => 'open', 'type' => 'advance', 'due_at' => now()->addDays(3), 'customer_name' => 'A',
            'customer_mobile' => '9000000044'], [['mode' => 'cash', 'amount' => 300]], $this->owner);
        $this->assertEquals(300, (float) CashCollection::sole()->cash);
        Billing::cancel($o, 'customer cancelled', $this->owner);
        $this->assertSame(0, CashCollection::count());
    }

    public function test_open_order_cannot_be_held_or_shrunk_below_paid(): void
    {
        $o = Billing::save([$this->line($this->cake)], ['mode' => 'open', 'type' => 'advance', 'due_at' => now()->addDay(), 'customer_name' => 'A',
            'customer_mobile' => '9000000055'], [['mode' => 'cash', 'amount' => 500]], $this->owner);
        $this->expectException(ValidationException::class);
        Billing::save([$this->line($this->tea)], ['mode' => 'open', 'type' => 'advance', 'due_at' => now()->addDay(), 'customer_name' => 'A',
            'customer_mobile' => '9000000055'], [], $this->owner, $o); // ₹20 < ₹500 already paid
    }
}
