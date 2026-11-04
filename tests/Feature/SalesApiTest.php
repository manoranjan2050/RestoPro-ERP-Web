<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\ReceiptLines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private string $token;
    private Item $cake;
    private Item $tea;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('app_mode', 'advanced');
        Setting::put('business_name', 'Spice Garden');
        $this->owner = User::factory()->create(['role' => 'owner']);
        $this->token = $this->owner->createToken('phone')->plainTextToken;
        $c = ItemCategory::create(['name' => 'Cakes']);
        $this->cake = Item::create(['name' => 'Black Forest', 'price' => 450, 'category_id' => $c->id, 'track_stock' => true]);
        $this->cake->variants()->create(['name' => '1 kg', 'price' => 850]);
        $this->tea = Item::create(['name' => 'Masala Tea', 'price' => 20]);
    }

    private function api(): self
    {
        return $this->withToken($this->token);
    }

    public function test_meta_and_catalog(): void
    {
        $this->api()->getJson('/api/v1/meta')->assertOk()
            ->assertJsonPath('sales.mode', 'advanced')->assertJsonPath('sales.features.tables', true)->assertJsonPath('sales.print.width', '80')
            ->assertJsonPath('sales.pay_modes.2.code', 'card');
        $this->api()->getJson('/api/v1/sales/catalog')->assertOk()
            ->assertJsonPath('categories.0.name', 'Cakes')
            ->assertJsonPath('items.0.name', 'Black Forest')->assertJsonPath('items.0.variants.0.name', '1 kg')
            ->assertJsonPath('items.0.stock', 0)->assertJsonPath('items.1.stock', null);

        Setting::put('app_mode', 'simple');
        $this->api()->getJson('/api/v1/sales/catalog')->assertNotFound();
        $this->api()->getJson('/api/v1/meta')->assertJsonPath('sales.mode', 'simple');
    }

    public function test_counter_bill_with_print_lines_and_pdf(): void
    {
        $kg = $this->cake->variants()->value('id');
        $res = $this->api()->postJson('/api/v1/sales/orders', [
            'cart' => [['item_id' => $this->cake->id, 'variant_id' => $kg, 'qty' => 1, 'note' => 'Happy Birthday'], ['item_id' => $this->tea->id, 'qty' => 2]],
            'customer_mobile' => '9000000011', 'customer_name' => 'Priya',
            'payments' => [['mode' => 'upi', 'amount' => 890]],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.total', 890)
            ->assertJsonPath('data.items.0.variant_name', '1 kg');
        $id = $res->json('data.id');
        $this->assertNotEmpty($res->json('print')); // auto-print on by default
        $this->assertStringStartsWith('https://wa.me/919000000011', $res->json('data.whatsapp_url'));

        $lines = $this->api()->getJson("/api/v1/sales/orders/{$id}/print?width=58")->assertOk()->assertJsonPath('width', 32)->json('lines');
        $text = collect($lines)->pluck('t')->implode("\n");
        $this->assertStringContainsString('Spice Garden', $text);
        $this->assertStringContainsString('Rs.890', $text);
        $this->assertStringNotContainsString('₹', $text);
        foreach ($lines as $l) {
            if (isset($l['t']) && $l['s'] === 1) {
                $this->assertLessThanOrEqual(32, strlen($l['t']), $l['t']);
            }
        }

        $pdf = $this->api()->get("/api/v1/sales/orders/{$id}/pdf");
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->api()->getJson('/api/v1/sales/orders?tab=bills')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->api()->postJson('/api/v1/sales/orders', ['cart' => [['item_id' => $this->tea->id, 'qty' => 1]], 'payments' => [['mode' => 'cash', 'amount' => 5]]])
            ->assertStatus(422)->assertJsonValidationErrors('payments');
    }

    public function test_table_flow_over_api(): void
    {
        $t = DiningTable::create(['name' => 'T1']);
        $o = $this->api()->postJson('/api/v1/sales/orders', ['cart' => [['item_id' => $this->tea->id, 'qty' => 3]], 'type' => 'dine_in', 'table_id' => $t->id, 'mode' => 'open'])
            ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.table.name', 'T1')->json('data');
        $this->assertSame([], $this->api()->postJson('/api/v1/sales/orders', ['cart' => [['item_id' => $this->tea->id, 'qty' => 3]], 'type' => 'dine_in', 'table_id' => $t->id, 'mode' => 'open'])
            ->assertStatus(422)->json('print') ?? []);

        $k = $this->api()->postJson("/api/v1/sales/orders/{$o['id']}/kot")->assertOk()->assertJsonPath('kot_no', 1);
        $this->assertStringContainsString('KITCHEN ORDER', collect($k->json('print'))->pluck('t')->implode(' '));
        $this->api()->getJson('/api/v1/sales/tables')->assertOk()->assertJsonPath('data.0.order.id', $o['id']);
        $this->api()->getJson('/api/v1/sales/orders?tab=running')->assertJsonCount(1, 'data');

        // add a round, keep KOT 1 on the tea line, then settle
        $cart = [['item_id' => $this->tea->id, 'qty' => 3, 'kot_no' => 1], ['item_id' => $this->cake->id, 'variant_id' => $this->cake->variants()->value('id'), 'qty' => 1]];
        $this->api()->putJson("/api/v1/sales/orders/{$o['id']}", ['cart' => $cart, 'type' => 'dine_in', 'table_id' => $t->id, 'mode' => 'open'])->assertOk();
        $this->api()->postJson("/api/v1/sales/orders/{$o['id']}/kot")->assertJsonPath('kot_no', 2);
        $this->api()->putJson("/api/v1/sales/orders/{$o['id']}", ['cart' => $cart, 'type' => 'dine_in', 'table_id' => $t->id, 'payments' => [['mode' => 'cash', 'amount' => 910]]])
            ->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertNotNull(Order::find($o['id'])->number);
    }

    public function test_advance_and_delivery_stage_and_cancel(): void
    {
        $o = $this->api()->postJson('/api/v1/sales/orders', [
            'cart' => [['item_id' => $this->cake->id, 'variant_id' => $this->cake->variants()->value('id'), 'qty' => 1]],
            'type' => 'advance', 'mode' => 'open', 'due_at' => now()->addDay()->format('Y-m-d').' 17:30',
            'customer_name' => 'Meena', 'customer_mobile' => '9000000033', 'details' => ['message' => 'Happy Birthday Riya', 'eggless' => true],
            'payments' => [['mode' => 'upi', 'amount' => 300]],
        ])->assertCreated()->assertJsonPath('data.balance', 550)->assertJsonPath('data.stage', 'booked')->assertJsonPath('data.details.eggless', true)->json('data');

        $this->api()->getJson('/api/v1/sales/orders?tab=advance')->assertJsonPath('data.0.id', $o['id']);
        $this->api()->postJson("/api/v1/sales/orders/{$o['id']}/stage", ['stage' => 'ready'])->assertOk()->assertJsonPath('data.stage_label', 'Ready');
        $this->api()->postJson("/api/v1/sales/orders/{$o['id']}/stage", ['stage' => 'nope'])->assertStatus(422);

        $cashier = User::factory()->create(['role' => 'cashier']);
        $ct = $cashier->createToken('t')->plainTextToken;
        $this->app['auth']->forgetGuards(); // the test client otherwise keeps the owner signed in
        $this->withToken($ct)->getJson('/api/v1/sales/catalog')->assertOk();
        $this->withToken($ct)->postJson("/api/v1/sales/orders/{$o['id']}/cancel", ['reason' => 'changed mind'])->assertForbidden();
        $this->withToken($ct)->getJson('/api/v1/sales/day-end')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson("/api/v1/sales/orders/{$o['id']}/cancel", ['reason' => 'changed mind'])->assertOk();
        $this->assertSame('cancelled', Order::find($o['id'])->status);

        $d = $this->withToken($this->token)->getJson('/api/v1/sales/day-end')->assertOk()->assertJsonPath('title', 'Day-end report');
        $this->assertNotEmpty($d->json('print'));
    }

    public function test_item_with_sizes_needs_a_size(): void
    {
        $this->api()->postJson('/api/v1/sales/orders', ['cart' => [['item_id' => $this->cake->id, 'qty' => 1]], 'payments' => [['mode' => 'cash', 'amount' => 450]]])
            ->assertStatus(422)->assertJsonPath('errors.cart.0', 'Choose a size for Black Forest.');
    }

    public function test_receipt_lines_fit_80mm(): void
    {
        $o = $this->api()->postJson('/api/v1/sales/orders', ['cart' => [['item_id' => $this->tea->id, 'qty' => 1]], 'payments' => [['mode' => 'cash', 'amount' => 20]]])->json('data');
        foreach (ReceiptLines::receipt(Order::find($o['id']), '80') as $l) {
            if (isset($l['t'])) {
                $this->assertLessThanOrEqual($l['s'] === 2 ? 24 : 48, strlen($l['t']), $l['t']);
                $this->assertMatchesRegularExpression('/^[\x20-\x7E]*$/', $l['t']);
            }
        }
    }
}
