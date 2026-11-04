<?php

namespace Tests\Feature;

use App\Livewire\Sales\Items;
use App\Livewire\Sales\OrderShow;
use App\Livewire\Sales\Pos;
use App\Models\CashCollection;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\BillCalculator;
use App\Services\Billing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Item $cake;
    private Item $puff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        Setting::put('app_mode', 'advanced');
        $this->cake = Item::create(['name' => 'Black Forest', 'price' => 450, 'gst_rate' => 5, 'hsn' => '1905']);
        $this->cake->variants()->create(['name' => '500 g', 'price' => 450, 'barcode' => '111']);
        $this->cake->variants()->create(['name' => '1 kg', 'price' => 850]);
        $this->puff = Item::create(['name' => 'Veg Puff', 'price' => 25, 'gst_rate' => 5, 'code' => 'VP']);
    }

    private function line(Item $i, float $qty = 1, ?string $variant = null): array
    {
        return ['item_id' => $i->id, 'variant_id' => $variant ? $i->variants()->where('name', $variant)->value('id') : null, 'qty' => $qty, 'note' => ''];
    }

    // ------------------------------------------------------------------ maths

    public function test_calculator_without_gst_discount_and_round_off(): void
    {
        $t = BillCalculator::calc([['qty' => 3, 'rate' => 33.3], ['qty' => 1, 'rate' => 100]], 10, 'pct', false, true, true);
        $this->assertSame([199.9, 19.99, 179.91, 180.0, 0.09], [$t['subtotal'], $t['discount'], $t['taxable'], $t['total'], $t['round_off']]);
        $this->assertEqualsWithDelta(19.99, $t['lines'][0]['discount'] + $t['lines'][1]['discount'], 0.001);
    }

    public function test_calculator_gst_inclusive_and_exclusive(): void
    {
        $inc = BillCalculator::calc([['qty' => 1, 'rate' => 525, 'gst_rate' => 5]], 0, 'amt', true, true, false);
        $this->assertSame([500.0, 25.0, 12.5, 12.5, 525.0], [$inc['taxable'], $inc['tax'], $inc['cgst'], $inc['sgst'], $inc['total']]);

        $exc = BillCalculator::calc([['qty' => 2, 'rate' => 100, 'gst_rate' => 18], ['qty' => 1, 'rate' => 50, 'gst_rate' => 5]], 0, 'amt', true, false, false);
        $this->assertSame([250.0, 38.5, 288.5], [$exc['taxable'], $exc['tax'], $exc['total']]);
        $this->assertSame(['5' => ['taxable' => 50.0, 'tax' => 2.5], '18' => ['taxable' => 200.0, 'tax' => 36.0]], $exc['by_rate']);

        $extra = BillCalculator::calc([['qty' => 1, 'rate' => 99.6]], 0, 'amt', false, true, true, 30);
        $this->assertSame(130.0, $extra['total']);
    }

    // ------------------------------------------------------------------ saving

    public function test_save_numbers_bill_and_feeds_daily_sales(): void
    {
        CashCollection::create(['date' => today()->toDateString(), 'cash' => 1000]);
        $o = Billing::save([$this->line($this->cake, 1, '1 kg'), $this->line($this->puff, 2)],
            ['customer_mobile' => '98765 43210', 'customer_name' => 'Priya'], [['mode' => 'cash', 'amount' => 900]], $this->owner);

        $fy = Billing::fyLabel();
        $this->assertSame([$fy.'/0001', 'completed', 900.0, 900.0], [$o->number, $o->status, (float) $o->total, (float) $o->paid]);
        $this->assertSame(['1 kg', 850.0], [$o->items[0]->variant_name, (float) $o->items[0]->rate]);
        $this->assertSame('Priya', Customer::where('mobile', '9876543210')->value('name'));

        $c = CashCollection::sole();
        $this->assertEquals([1900, 900], [(float) $c->cash, (float) $c->pos_cash]);

        Setting::put('bill_prefix', 'SG/');
        $o2 = Billing::save([$this->line($this->puff)], [], [['mode' => 'upi', 'amount' => 15], ['mode' => 'card', 'amount' => 10]], $this->owner);
        $this->assertSame('SG/'.$fy.'/0002', $o2->number);
        $c->refresh();
        $this->assertEquals([1900, 15, 10, 1925], [(float) $c->cash, (float) $c->upi, (float) $c->online, (float) $c->total]);
    }

    public function test_payments_must_match_and_prices_come_from_the_item_list(): void
    {
        $this->expectException(ValidationException::class);
        Billing::save([$this->line($this->puff, 2)], [], [['mode' => 'cash', 'amount' => 40]], $this->owner);
    }

    public function test_gst_bill_is_stored_with_tax(): void
    {
        Setting::put('gst_enabled', '1');
        Setting::put('pos_round_off', '0');
        $o = Billing::save([$this->line($this->cake, 1, '500 g')], [], [['mode' => 'cash', 'amount' => 450]], $this->owner);
        $this->assertTrue($o->gst_enabled);
        $this->assertSame([428.57, 10.72, 10.71, 450.0], [(float) $o->taxable, (float) $o->cgst, (float) $o->sgst, (float) $o->total]);
        $this->assertSame('1905', $o->items[0]->hsn);
    }

    public function test_hold_then_complete(): void
    {
        $held = Billing::save([$this->line($this->puff, 4)], ['hold' => true], [], $this->owner);
        $this->assertSame(['held', null], [$held->status, $held->number]);
        $this->assertSame(0, CashCollection::count());

        $done = Billing::save([$this->line($this->puff, 4)], [], [['mode' => 'cash', 'amount' => 100]], $this->owner, $held);
        $this->assertSame([$held->id, 'completed'], [$done->id, $done->status]);
        $this->assertNotNull($done->number);
        $this->assertSame(1, $done->items()->count());
    }

    public function test_discount_limit_for_staff(): void
    {
        Setting::put('pos_max_discount', '10');
        $manager = User::factory()->create(['role' => 'manager']);
        try {
            Billing::save([$this->line($this->puff, 4)], ['discount_value' => 20, 'discount_type' => 'pct'], [['mode' => 'cash', 'amount' => 80]], $manager);
            $this->fail('expected the discount to be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('discount', $e->errors());
        }
        // the owner may
        $o = Billing::save([$this->line($this->puff, 4)], ['discount_value' => 20, 'discount_type' => 'pct'], [['mode' => 'cash', 'amount' => 80]], $this->owner);
        $this->assertEquals(20, (float) $o->discount);
    }

    public function test_cancel_removes_from_sales_but_keeps_number(): void
    {
        $o = Billing::save([$this->line($this->puff, 2)], [], [['mode' => 'cash', 'amount' => 50]], $this->owner);
        $this->assertEquals(50, (float) CashCollection::sole()->total);

        $manager = User::factory()->create(['role' => 'manager']);
        Livewire::actingAs($manager)->test(OrderShow::class, ['order' => $o])->call('askCancel')->assertForbidden();

        Livewire::actingAs($this->owner)->test(OrderShow::class, ['order' => $o])
            ->call('askCancel')->set('reason', '')->call('cancelBill')->assertHasErrors('reason')
            ->set('reason', 'Customer returned')->call('cancelBill')->assertHasNoErrors();
        $o->refresh();
        $this->assertSame(['cancelled', 'Customer returned'], [$o->status, $o->cancel_reason]);
        $this->assertNotNull($o->number);
        $this->assertSame(0, CashCollection::count()); // that day only had this bill

        $next = Billing::save([$this->line($this->puff)], [], [['mode' => 'cash', 'amount' => 25]], $this->owner);
        $this->assertSame(2, $next->seq);
    }

    public function test_sold_item_is_hidden_not_deleted(): void
    {
        Billing::save([$this->line($this->puff)], [], [['mode' => 'cash', 'amount' => 25]], $this->owner);
        Livewire::actingAs($this->owner)->test(Items::class)->call('confirmDelete', $this->puff->id)->call('delete');
        $this->assertFalse($this->puff->fresh()->is_active);
    }

    // ------------------------------------------------------------------ screen

    public function test_pos_screen_flow(): void
    {
        $page = Livewire::actingAs($this->owner)->test(Pos::class)
            ->call('add', $this->cake->id)->assertSet('pickingItem', $this->cake->id)
            ->call('add', $this->cake->id, $this->cake->variants()->where('name', '1 kg')->value('id'))
            ->set('search', '111')->call('scan')->assertSet('search', '')   // 500 g by barcode
            ->set('search', 'vp')->call('scan')                             // puff by short code
            ->call('add', $this->puff->id)
            ->assertCount('cart', 3)
            ->set('discount_value', '25')
            ->call('openPay')->assertSet('showPay', true)->assertSet('payments.0.amount', '1325')
            ->call('payAll', 'upi')
            ->call('complete')->assertHasNoErrors();

        $o = Order::sole();
        $this->assertSame([1325.0, 25.0, 3], [(float) $o->total, (float) $o->discount, $o->items()->count()]);
        $this->assertEquals(2, $o->items()->where('item_id', $this->puff->id)->value('qty'));
        $this->assertEquals(1325, (float) CashCollection::sole()->upi);
        $page->assertSet('cart', [])->assertSet('lastOrderId', $o->id);

        $this->actingAs($this->owner)->get(route('orders'))->assertOk()->assertSee($o->number);
        $this->get(route('orders.show', $o))->assertOk()->assertSee('Black Forest')->assertSee('1 kg');
    }

    public function test_cashier_uses_the_counter_only(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier)->get('/')->assertRedirect(route('pos'));
        $this->get(route('pos'))->assertOk()->assertDontSee(route('expenses'));
        $this->get(route('orders'))->assertOk();

        Setting::put('app_mode', 'simple');
        $this->get(route('pos'))->assertNotFound();
    }

    public function test_quantity_and_note_follow_the_line_not_its_position(): void
    {
        $page = Livewire::actingAs($this->owner)->test(Pos::class)
            ->call('add', $this->cake->id, $this->cake->variants()->where('name', '1 kg')->value('id'))
            ->call('add', $this->puff->id)->call('add', $this->puff->id); // puff on top, cake second
        $cakeKey = collect($page->get('cart'))->firstWhere('item_id', $this->cake->id)['key'];
        $page->call('setQty', $cakeKey, '3')->call('setNote', $cakeKey, 'Happy birthday Riya');
        $cart = collect($page->get('cart'))->keyBy('item_id');
        $this->assertEquals([3, 2], [$cart[$this->cake->id]['qty'], $cart[$this->puff->id]['qty']]);
        $this->assertSame('Happy birthday Riya', $cart[$this->cake->id]['note']);
        $page->call('setQty', $cakeKey, '0')->assertCount('cart', 1);
    }

    public function test_hold_and_resume_on_screen(): void
    {
        Livewire::actingAs($this->owner)->test(Pos::class)
            ->call('add', $this->puff->id)->set('customer_name', 'Ravi')->call('hold')->assertSet('cart', []);
        $held = Order::where('status', 'held')->sole();
        Livewire::actingAs($this->owner)->test(Pos::class)
            ->call('resume', $held->id)->assertSet('heldId', $held->id)->assertSet('customer_name', 'Ravi')->assertCount('cart', 1)
            ->call('openPay')->call('complete')->assertHasNoErrors();
        $this->assertSame('completed', $held->fresh()->status);
    }
}
