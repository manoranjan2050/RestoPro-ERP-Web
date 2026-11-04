<?php

namespace Tests\Feature;

use App\Livewire\Collections;
use App\Livewire\Sales\Customers;
use App\Livewire\Sales\Items;
use App\Livewire\SettingsPage;
use App\Models\CashCollection;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Setting;
use App\Models\User;
use App\Services\DaySales;
use App\Services\ItemImport;
use App\Services\ReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SalesFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    private function advanced(): void
    {
        Setting::put('app_mode', 'advanced');
    }

    // ------------------------------------------------------------------ mode

    public function test_simple_mode_hides_sales_screens(): void
    {
        $this->actingAs($this->owner)->get('/items')->assertNotFound();
        $this->get('/customers')->assertNotFound();
        $this->get('/')->assertDontSee('Customers');

        $this->advanced();
        $this->get('/items')->assertOk();
        $this->get('/customers')->assertOk();
        $this->get('/')->assertSee(route('items'))->assertSee(route('customers'))->assertSee('POS');
    }

    public function test_owner_switches_mode_and_features(): void
    {
        Livewire::actingAs($this->owner)->test(SettingsPage::class)
            ->set('mode', 'advanced')->set('features.tables', false)->call('saveMode')->assertRedirect();
        $this->assertSame('advanced', Setting::get('app_mode'));
        $this->assertSame('0', Setting::get('sales_tables'));
        $this->assertFalse(\App\Support\Sales::on('tables'));
        $this->assertTrue(\App\Support\Sales::on('stock'));
        $this->actingAs($this->owner)->get('/')->assertDontSee('>Tables<', false);
    }

    public function test_gst_needs_a_gstin(): void
    {
        $this->advanced();
        $page = Livewire::actingAs($this->owner)->test(SettingsPage::class)->set('gst_enabled', true)->call('saveGst')->assertHasErrors('gst_enabled');
        $this->assertNotSame('1', Setting::get('gst_enabled'));

        Setting::put('business_gstin', '21ABCDE1234F1Z5');
        $page->set('gst_inclusive', '0')->call('saveGst')->assertHasNoErrors();
        $this->assertTrue(\App\Support\Sales::gst());
        $this->assertFalse(\App\Support\Sales::gstInclusive());
    }

    public function test_cashier_has_no_back_office(): void
    {
        $this->advanced();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier);
        foreach (['/collections', '/expenses', '/staff', '/reports', '/items', '/customers', '/ledger'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/')->assertRedirect(route('pos'));
        $this->get('/profile')->assertOk()->assertDontSee(route('expenses'));

        $token = $cashier->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/collections')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    }

    public function test_owner_can_create_cashier_login(): void
    {
        Livewire::actingAs($this->owner)->test(SettingsPage::class)
            ->call('newUser')->set('u.name', 'Counter 1')->set('u.username', 'counter1')->set('u.role', 'cashier')
            ->set('u.password', 'secret-123')->call('saveUser')->assertHasNoErrors();
        $this->assertSame('cashier', User::where('username', 'counter1')->value('role'));
    }

    // ------------------------------------------------------------------ items

    public function test_item_with_sizes_and_photo(): void
    {
        $this->advanced();
        Storage::fake('public');
        $cat = ItemCategory::create(['name' => 'Cakes']);

        Livewire::actingAs($this->owner)->test(Items::class)
            ->call('create')
            ->set('f.name', 'Black Forest')->set('f.category_id', $cat->id)->set('f.code', 'bf')->set('f.food_type', 'egg')
            ->set('f.hsn', '1905')->set('photo', UploadedFile::fake()->image('bf.jpg'))
            ->call('addVariant')->set('variants.0.name', '500 g')->set('variants.0.price', '450')
            ->call('addVariant')->set('variants.1.name', '1 kg')->set('variants.1.price', '850')
            ->call('save')->assertHasNoErrors();

        $item = Item::with('variants')->sole();
        $this->assertSame(['BF', 450.0, '1905'], [$item->code, (float) $item->price, $item->hsn]);
        $this->assertSame(['500 g', '1 kg'], $item->variants->pluck('name')->all());
        $this->assertSame('₹450 – ₹850', $item->priceLabel());
        Storage::disk('public')->assertExists($item->photo_path);
        $this->get(route('media', $item->photo_path))->assertOk();

        // edit: drop a size, rename the other
        Livewire::actingAs($this->owner)->test(Items::class)->call('edit', $item->id)
            ->call('removeVariant', 1)->set('variants.0.name', 'Half kg')->call('save')->assertHasNoErrors();
        $this->assertSame(['Half kg'], $item->fresh()->variants->pluck('name')->all());
    }

    public function test_item_validation(): void
    {
        $this->advanced();
        Item::create(['name' => 'Puff', 'code' => 'VP', 'barcode' => '890100', 'price' => 20]);
        Livewire::actingAs($this->owner)->test(Items::class)->call('create')
            ->set('f.name', '')->set('f.code', 'vp')->set('f.barcode', '890100')->set('f.price', '')->set('f.hsn', '12')
            ->call('save')->assertHasErrors(['f.name', 'f.code', 'f.barcode', 'f.price', 'f.hsn']);

        Livewire::actingAs($this->owner)->test(Items::class)->call('create')->set('f.name', 'Cake')
            ->call('addVariant')->set('variants.0.name', '1 kg')->set('variants.0.price', '800')
            ->call('addVariant')->set('variants.1.name', '1 KG')->set('variants.1.price', '900')
            ->call('save')->assertHasErrors('variants.1.name');
    }

    public function test_search_finds_variant_barcode_and_manager_cannot_delete(): void
    {
        $this->advanced();
        $item = Item::create(['name' => 'Truffle', 'price' => 0]);
        $item->variants()->create(['name' => '1 kg', 'price' => 900, 'barcode' => '777001']);
        $this->assertSame($item->id, Item::search('777001')->value('id'));

        $manager = User::factory()->create(['role' => 'manager']);
        Livewire::actingAs($manager)->test(Items::class)->call('confirmDelete', $item->id)->assertForbidden();
    }

    public function test_csv_import_creates_and_updates(): void
    {
        $this->advanced();
        Item::create(['name' => 'Veg Puff', 'code' => 'VP', 'price' => 20]);
        $csv = "Item Name,Category,Price (₹),Sizes,Code,GST,HSN,Type\n"
            ."Black Forest,Cakes,,500 g=450; 1 kg=850,BF,5,1905,egg\n"
            ."Veg Puff,Snacks,25,,VP,5,,veg\n"
            .",,,,,,,\n";
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $csv);

        $p = ItemImport::preview($path, 'csv');
        $this->assertSame([2, 1, 1, []], [$p['rows'], $p['create'], $p['update'], $p['errors']]);
        $this->assertSame(['created' => 1, 'updated' => 1], ItemImport::run($path, 'csv'));

        $bf = Item::with('variants', 'category')->where('code', 'BF')->first();
        $this->assertSame(['Cakes', 'egg', 2], [$bf->category->name, $bf->food_type, $bf->variants->count()]);
        $this->assertEquals(25, Item::where('code', 'VP')->value('price'));
        $this->assertSame(1, Item::where('code', 'VP')->count());

        file_put_contents($path, "name,price,gst,sizes\nBad,abc,7,500g\n");
        $this->assertCount(3, ItemImport::preview($path, 'csv')['errors']);

        $this->actingAs($this->owner)->get(route('items.template'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    // ------------------------------------------------------------------ customers

    public function test_customer_crud_and_unique_mobile(): void
    {
        $this->advanced();
        Livewire::actingAs($this->owner)->test(Customers::class)
            ->set('search', '98765 43210')->call('create')->assertSet('c.mobile', '9876543210')
            ->set('c.name', 'Priya')->set('c.birthday', today()->subYears(30)->addDays(2)->toDateString())
            ->call('save')->assertHasNoErrors();
        $this->assertSame('9876543210', Customer::sole()->mobile);

        Livewire::actingAs($this->owner)->test(Customers::class)->call('create')
            ->set('c.name', 'Other')->set('c.mobile', '+91 98765-43210')->call('save')->assertHasErrors('c.mobile');

        $this->actingAs($this->owner)->get('/customers')->assertSee('Priya')->assertSee('Coming up this week');
        $this->assertSame(1, Customer::search('43210')->count());
    }

    // ------------------------------------------------------------------ day sales

    public function test_pos_share_is_added_on_top_of_hand_entered_sales(): void
    {
        $d = today()->toDateString();
        CashCollection::create(['date' => $d, 'cash' => 1000, 'upi' => 500]);

        DaySales::setPos($d, ['cash' => 300, 'upi' => 200, 'online' => 50]);
        $c = CashCollection::sole();
        $this->assertEquals([1300, 700, 50, 2050], [(float) $c->cash, (float) $c->upi, (float) $c->online, (float) $c->total]);
        $this->assertSame(['cash' => 1000.0, 'upi' => 500.0, 'online' => 0.0], DaySales::manual($c));

        // setting it again replaces (not adds)
        DaySales::setPos($d, ['cash' => 100]);
        $this->assertEquals(1600, (float) CashCollection::sole()->total);

        // reports read the totals
        $r = ReportBuilder::build('monthly', ['month' => today()->format('Y-m')]);
        $this->assertEquals(1600, collect($r['kpis'])->firstWhere(0, 'Sales')[1]);
    }

    public function test_pos_only_day_is_created_and_removed(): void
    {
        $d = today()->subDay()->toDateString();
        DaySales::setPos($d, ['upi' => 400]);
        $this->assertEquals(400, (float) CashCollection::sole()->upi);
        DaySales::setPos($d, []);
        $this->assertSame(0, CashCollection::count());
        $this->assertNull(DaySales::setPos($d, []));
    }

    public function test_collection_form_edits_only_the_hand_entered_part(): void
    {
        $d = today()->toDateString();
        $c = CashCollection::create(['date' => $d, 'cash' => 1000]);
        DaySales::setPos($d, ['cash' => 300]);

        Livewire::actingAs($this->owner)->test(Collections::class)
            ->call('edit', $c->id)->assertSet('cash', '1000')->assertSet('pos.cash', 300.0)
            ->set('cash', '1200')->call('save')->assertHasNoErrors();
        $this->assertEquals(1500, (float) $c->fresh()->cash);

        // delete keeps the bills
        Livewire::actingAs($this->owner)->test(Collections::class)->call('confirmDelete', $c->id)->call('delete');
        $this->assertEquals(300, (float) $c->fresh()->cash);
        $this->assertEquals(300, (float) $c->fresh()->pos_cash);
    }

    public function test_api_update_treats_amounts_as_day_totals(): void
    {
        $d = today()->toDateString();
        $c = CashCollection::create(['date' => $d, 'cash' => 1000]);
        DaySales::setPos($d, ['cash' => 300]);
        $token = $this->owner->createToken('t')->plainTextToken;

        $this->withToken($token)->putJson("/api/v1/collections/{$c->id}", ['date' => $d, 'cash' => 200])
            ->assertStatus(422)->assertJsonValidationErrors('cash');
        $this->withToken($token)->putJson("/api/v1/collections/{$c->id}", ['date' => $d, 'cash' => 1500])
            ->assertOk()->assertJsonPath('data.cash', 1500)->assertJsonPath('data.from_bills.cash', 300);
        $this->assertSame(['cash' => 1200.0, 'upi' => 0.0, 'online' => 0.0], DaySales::manual($c->fresh()));
    }
}
