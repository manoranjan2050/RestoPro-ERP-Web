<?php

namespace Tests\Feature;

use App\Livewire\Sales\Pos;
use App\Livewire\SettingsPage;
use App\Models\Item;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing;
use App\Services\BillView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PrintingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Item $cake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'name' => 'Rahul']);
        Setting::put('app_mode', 'advanced');
        Setting::put('business_name', 'Spice Garden');
        Setting::put('business_address', 'Station Road');
        $this->cake = Item::create(['name' => 'Black Forest', 'price' => 450, 'gst_rate' => 5, 'hsn' => '1905']);
    }

    private function bill(array $opts = []): Order
    {
        return Billing::save([['item_id' => $this->cake->id, 'variant_id' => null, 'qty' => 2, 'note' => 'Happy birthday']],
            $opts + ['customer_mobile' => '9000000011', 'customer_name' => 'Priya'], [['mode' => 'upi', 'amount' => 900]], $this->owner);
    }

    public function test_receipt_shows_the_bill(): void
    {
        Setting::put('print_footer', 'Thank you, come again!');
        $o = $this->bill();
        $this->actingAs($this->owner)->get(route('orders.print', $o))->assertOk()
            ->assertSee('Spice Garden')->assertSee('Station Road')->assertSee($o->number)->assertSee('BILL')
            ->assertSee('Black Forest')->assertSee('Happy birthday')->assertSee('TOTAL')->assertSee('Paid by UPI')
            ->assertSee('Thank you, come again!')->assertSee('size: 80mm auto', false)->assertDontSee('GSTIN');

        $this->get(route('orders.print', $o).'?w=58')->assertSee('size: 58mm auto', false);
        $this->get(route('orders.print', $o).'?auto=1')->assertSee('window.print()', false);
    }

    public function test_gst_receipt_is_a_tax_invoice(): void
    {
        Setting::put('business_gstin', '21ABCDE1234F1Z5');
        Setting::put('gst_enabled', '1');
        $o = $this->bill();
        $this->actingAs($this->owner)->get(route('orders.print', $o))
            ->assertSee('TAX INVOICE')->assertSee('GSTIN: 21ABCDE1234F1Z5')->assertSee('CGST')->assertSee('HSN 1905');
    }

    public function test_kot_has_items_but_no_prices(): void
    {
        $o = $this->bill();
        $this->actingAs($this->owner)->get(route('orders.kot', $o))->assertOk()
            ->assertSee('KITCHEN ORDER')->assertSee('Black Forest')->assertSee('Happy birthday')->assertDontSee('900');
    }

    public function test_pdf_and_held_bill(): void
    {
        $o = $this->bill();
        $res = $this->actingAs($this->owner)->get(route('orders.pdf', $o));
        $res->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());

        $held = Billing::save([['item_id' => $this->cake->id, 'qty' => 1]], ['hold' => true], [], $this->owner);
        $this->get(route('orders.print', $held))->assertNotFound();

        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier)->get(route('orders.print', $o))->assertOk();
        $this->get(route('orders.pdf', $o))->assertOk();
    }

    public function test_customer_link_works_without_login(): void
    {
        $o = $this->bill();
        $this->assertNotNull($o->share_token);
        $this->get(route('bill.public', $o->share_token))->assertOk()
            ->assertHeader('X-Robots-Tag')->assertSee('Spice Garden')->assertSee($o->number)->assertSee('Black Forest')
            ->assertSee('Download PDF')->assertDontSee('Rahul'); // no staff names on the customer's page
        $this->get(route('bill.public.pdf', $o->share_token))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/b/'.str_repeat('x', 32))->assertNotFound();

        Setting::put('app_mode', 'simple'); // links already sent keep working
        $this->get(route('bill.public', $o->share_token))->assertOk();

        Billing::cancel($o, 'returned', $this->owner);
        $this->get(route('bill.public', $o->share_token))->assertSee('This bill was cancelled');
    }

    public function test_whatsapp_link(): void
    {
        $o = $this->bill();
        $url = BillView::whatsappUrl($o);
        $this->assertStringStartsWith('https://wa.me/919000000011?text=', $url);
        $text = rawurldecode(substr($url, strpos($url, 'text=') + 5));
        $this->assertStringContainsString('Hi Priya', $text);
        $this->assertStringContainsString(route('bill.public', $o->share_token), $text);

        $walkin = Billing::save([['item_id' => $this->cake->id, 'qty' => 1]], [], [['mode' => 'cash', 'amount' => 450]], $this->owner);
        $this->assertStringStartsWith('https://wa.me/?text=', BillView::whatsappUrl($walkin));
    }

    public function test_printing_settings_and_auto_print_event(): void
    {
        Livewire::actingAs($this->owner)->test(SettingsPage::class)
            ->set('printing.width', '58')->set('printing.footer', 'Visit again')->set('printing.kot', true)->call('savePrinting')->assertHasNoErrors();
        $this->assertSame(['58', 'Visit again', '1'], [Setting::get('print_width'), Setting::get('print_footer'), Setting::get('print_kot')]);

        Livewire::actingAs($this->owner)->test(Pos::class)
            ->call('add', $this->cake->id)->call('openPay')->call('complete')
            ->assertDispatched('bill-saved', fn ($name, $p) => str_contains($p['print'], '/print?auto=1') && str_contains($p['kot'], '/kot?auto=1'));

        Setting::put('print_auto', '0');
        Setting::put('print_kot', '0');
        Livewire::actingAs($this->owner)->test(Pos::class)
            ->call('add', $this->cake->id)->call('openPay')->call('complete')
            ->assertDispatched('bill-saved', fn ($name, $p) => $p['print'] === null && $p['kot'] === null);
    }
}
