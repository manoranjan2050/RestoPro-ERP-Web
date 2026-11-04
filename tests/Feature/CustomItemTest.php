<?php

namespace Tests\Feature;

use App\Livewire\Sales\Pos;
use App\Models\CashCollection;
use App\Models\Item;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CustomItemTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        Setting::put('app_mode', 'advanced');
    }

    public function test_custom_cake_booked_as_advance_order_then_collected(): void
    {
        $due = today()->addDays(2)->toDateString();
        Livewire::actingAs($this->owner)->test(Pos::class)
            ->call('setType', 'advance')
            ->call('openCustom')->set('customName', '')->call('addCustom')->assertHasErrors('customName')
            ->set('customName', 'Photo cake, Spider-Man theme')->set('customPrice', '1800')->set('customQty', '1')->call('addCustom')
            ->assertHasNoErrors()->assertCount('cart', 1)
            ->set('customer_mobile', '9000000201')->set('customer_name', 'Anita')
            ->set('due_date', $due)->set('due_time', '18:00')
            ->set('details.weight', '2 lb')->set('details.message', 'Happy 5th Birthday Aarav')
            ->call('openPay', 'open')->set('payments.0.amount', '800')->call('complete')->assertHasNoErrors();

        $o = Order::with('items')->sole();
        $this->assertSame(['open', 1800.0, 800.0, 1000.0], [$o->status, (float) $o->total, (float) $o->paid, $o->balance()]);
        $this->assertSame([null, 'Photo cake, Spider-Man theme', 1800.0], [$o->items[0]->item_id, $o->items[0]->name, (float) $o->items[0]->rate]);
        $this->assertSame(['weight' => '2 lb', 'message' => 'Happy 5th Birthday Aarav'], $o->details);
        $this->assertEquals(800, (float) CashCollection::sole()->cash);

        // pickup: the custom line comes back on the POS and the balance is collected
        Livewire::actingAs($this->owner)->test(Pos::class, ['openOrder' => $o->id])
            ->assertCount('cart', 1)->assertSet('cart.0.custom.name', 'Photo cake, Spider-Man theme')
            ->call('openPay')->assertSet('payments.0.amount', '1000')->call('complete')->assertHasNoErrors();
        $o->refresh();
        $this->assertSame(['completed', 1800.0], [$o->status, (float) $o->paid]);
        $this->actingAs($this->owner)->get(route('orders.print', $o))->assertSee('Photo cake, Spider-Man theme')->assertSee('Happy 5th Birthday Aarav');
    }

    public function test_custom_line_mixed_with_menu_items_and_gst(): void
    {
        Setting::put('gst_enabled', '1');
        Setting::put('pos_round_off', '0');
        $tea = Item::create(['name' => 'Tea', 'price' => 21, 'gst_rate' => 5]);
        $o = Billing::save([
            ['item_id' => $tea->id, 'qty' => 1],
            ['item_id' => null, 'qty' => 2, 'custom' => ['name' => 'Fondant topper', 'rate' => 105, 'gst_rate' => 5]],
        ], [], [['mode' => 'upi', 'amount' => 231]], $this->owner);
        $this->assertEquals([231, 220, 11], [(float) $o->total, (float) $o->taxable, (float) $o->cgst + (float) $o->sgst]);

        foreach ([['name' => '', 'rate' => 10], ['name' => 'X', 'rate' => -1], ['name' => 'X', 'rate' => 10, 'gst_rate' => 7]] as $bad) {
            try {
                Billing::save([['item_id' => null, 'qty' => 1, 'custom' => $bad]], [], [['mode' => 'cash', 'amount' => 10]], $this->owner);
                $this->fail('expected validation for '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('cart', $e->errors());
            }
        }
    }

    public function test_custom_line_over_the_api(): void
    {
        $token = $this->owner->createToken('t')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/sales/orders', [
            'cart' => [['custom' => ['name' => 'Custom anniversary cake', 'rate' => 1500], 'qty' => 1]],
            'type' => 'advance', 'mode' => 'open', 'due_at' => today()->addDay()->format('Y-m-d').' 17:00',
            'customer_name' => 'Ravi', 'customer_mobile' => '9000000202', 'payments' => [['mode' => 'cash', 'amount' => 500]],
        ])->assertCreated()->assertJsonPath('data.items.0.item_id', null)->assertJsonPath('data.items.0.name', 'Custom anniversary cake')
            ->assertJsonPath('data.balance', 1000);

        $this->withToken($token)->postJson('/api/v1/sales/orders', ['cart' => [['qty' => 1]], 'payments' => []])
            ->assertStatus(422)->assertJsonValidationErrors('cart.0.item_id');
    }
}
