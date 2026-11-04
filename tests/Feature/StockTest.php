<?php

namespace Tests\Feature;

use App\Livewire\Sales\Pos;
use App\Livewire\Sales\StockPage;
use App\Models\DiningTable;
use App\Models\Item;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Billing;
use App\Services\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Item $pastry;
    private Item $cake;
    private Item $tea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        Setting::put('app_mode', 'advanced');
        $this->pastry = Item::create(['name' => 'Choco Pastry', 'price' => 70, 'track_stock' => true, 'low_stock_at' => 3]);
        $this->cake = Item::create(['name' => 'Black Forest', 'price' => 0, 'track_stock' => true]);
        $this->cake->variants()->create(['name' => '500 g', 'price' => 450]);
        $this->cake->variants()->create(['name' => '1 kg', 'price' => 850]);
        $this->tea = Item::create(['name' => 'Tea', 'price' => 20]); // not tracked
    }

    private function line(Item $i, float $qty, ?string $size = null): array
    {
        return ['item_id' => $i->id, 'variant_id' => $size ? $i->variants()->where('name', $size)->value('id') : null, 'qty' => $qty, 'note' => ''];
    }

    public function test_move_and_count(): void
    {
        $this->assertSame(10.0, Stock::move($this->pastry, null, 10, 'made', $this->owner));
        $this->assertSame(8.0, Stock::move($this->pastry, null, -2, 'wastage', $this->owner, 'dropped'));
        $this->assertSame(7.0, Stock::setCount($this->pastry, null, 7, $this->owner));
        $this->assertEquals(7, $this->pastry->fresh()->stock_qty);
        $this->assertSame(['made', 'wastage', 'adjust'], StockMovement::orderBy('id')->pluck('reason')->all());
        $this->assertEquals(7, StockMovement::latest('id')->value('balance'));

        $this->expectException(ValidationException::class);
        Stock::move($this->cake, null, 5, 'made'); // has sizes → must choose one
    }

    public function test_sale_takes_stock_and_cancel_puts_it_back(): void
    {
        Stock::move($this->pastry, null, 10, 'made');
        $kg = $this->cake->variants()->where('name', '1 kg')->first();
        Stock::move($this->cake, $kg, 2, 'made');

        $o = Billing::save([$this->line($this->pastry, 3), $this->line($this->cake, 1, '1 kg'), $this->line($this->tea, 2)], [],
            [['mode' => 'cash', 'amount' => 1100]], $this->owner);
        $this->assertEquals(7, $this->pastry->fresh()->stock_qty);
        $this->assertEquals(1, $kg->fresh()->stock_qty);
        $this->assertSame(0, StockMovement::where('item_id', $this->tea->id)->count()); // untracked
        $this->assertTrue($this->pastry->fresh()->load('variants')->stockOnHand() == 7);

        Billing::cancel($o, 'returned', $this->owner);
        $this->assertEquals(10, $this->pastry->fresh()->stock_qty);
        $this->assertEquals(2, $kg->fresh()->stock_qty);
    }

    public function test_held_bill_does_not_take_stock_and_table_edits_adjust(): void
    {
        Stock::move($this->pastry, null, 10, 'made');
        $held = Billing::save([$this->line($this->pastry, 4)], ['hold' => true], [], $this->owner);
        $this->assertEquals(10, $this->pastry->fresh()->stock_qty);

        $t = DiningTable::create(['name' => 'T1']);
        $o = Billing::save([$this->line($this->pastry, 4)], ['mode' => 'open', 'type' => 'dine_in', 'table_id' => $t->id], [], $this->owner, $held);
        $this->assertEquals(6, $this->pastry->fresh()->stock_qty);
        // guest changes their mind: 4 → 1
        $o = Billing::save([$this->line($this->pastry, 1)], ['mode' => 'open', 'type' => 'dine_in', 'table_id' => $t->id], [], $this->owner, $o);
        $this->assertEquals(9, $this->pastry->fresh()->stock_qty);
        // settle — no double count
        Billing::save([$this->line($this->pastry, 1)], ['type' => 'dine_in', 'table_id' => $t->id], [['mode' => 'cash', 'amount' => 70]], $this->owner, $o);
        $this->assertEquals(9, $this->pastry->fresh()->stock_qty);
        $this->assertEquals(-1, StockMovement::where('order_id', $o->id)->sum('qty'));
    }

    public function test_selling_below_zero_is_allowed_and_stock_off_means_no_tracking(): void
    {
        Billing::save([$this->line($this->pastry, 2)], [], [['mode' => 'cash', 'amount' => 140]], $this->owner);
        $this->assertEquals(-2, $this->pastry->fresh()->stock_qty);

        Setting::put('sales_stock', '0');
        Billing::save([$this->line($this->pastry, 2)], [], [['mode' => 'cash', 'amount' => 140]], $this->owner);
        $this->assertEquals(-2, $this->pastry->fresh()->stock_qty);
        $this->actingAs($this->owner)->get(route('stock'))->assertNotFound();
    }

    public function test_stock_page_actions(): void
    {
        $kg = $this->cake->variants()->where('name', '1 kg')->value('id');
        $page = Livewire::actingAs($this->owner)->test(StockPage::class)
            ->assertSee('Choco Pastry')->assertSee('Black Forest · 1 kg')->assertDontSee('Tea')
            ->call('openBulk', 'made')
            ->set('bulk.'.$this->pastry->id.'-0', '12')->set('bulk.'.$this->cake->id.'-'.$kg, '3')
            ->call('saveBulk')->assertHasNoErrors();
        $this->assertEquals(12, $this->pastry->fresh()->stock_qty);
        $this->assertEquals(3, $this->cake->variants()->find($kg)->stock_qty);

        $page->call('act', $this->pastry->id.'-0', 'wastage')->set('actQty', '2')->call('saveAct')->assertHasNoErrors()
            ->call('act', $this->pastry->id.'-0', 'count')->set('actQty', '2')->call('saveAct');
        $this->assertEquals(2, $this->pastry->fresh()->stock_qty);
        $page->set('lowOnly', true)->assertSee('Choco Pastry'); // 2 ≤ warn level 3

        $page->call('openTrack')->set('track.'.$this->tea->id, true)->call('saveTrack');
        $this->assertTrue($this->tea->fresh()->track_stock);

        $page->set('tab', 'history')->assertSee('Wastage / expired')->assertSee('Count adjusted');

        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier)->get(route('stock'))->assertForbidden();
    }

    public function test_pos_shows_stock_left(): void
    {
        Stock::move($this->pastry, null, 5, 'made');
        Livewire::actingAs($this->owner)->test(Pos::class)->assertSee('5 left')->assertSee('Out of stock'); // cake sizes have 0
    }
}
