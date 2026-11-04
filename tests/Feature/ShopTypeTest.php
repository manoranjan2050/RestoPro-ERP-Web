<?php

namespace Tests\Feature;

use App\Livewire\SettingsPage;
use App\Models\ItemCategory;
use App\Models\Setting;
use App\Models\User;
use App\Support\Sales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShopTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
    }

    public function test_cake_shop_preset(): void
    {
        Livewire::actingAs($this->owner)->test(SettingsPage::class)->call('chooseShopType', 'cake_shop')->assertRedirect();
        $this->assertSame(['cake_shop', 'advanced', 'both'], [Sales::shopType(), Setting::get('app_mode'), Sales::weightUnit()]);
        $this->assertFalse(Sales::on('tables'));
        $this->assertTrue(Sales::on('advance') && Sales::on('stock') && Sales::on('delivery'));

        Livewire::actingAs($this->owner)->test(SettingsPage::class)->call('addSuggestedCategories');
        $this->assertContains('Cakes', ItemCategory::pluck('name')->all());
        $n = ItemCategory::count();
        Livewire::actingAs($this->owner)->test(SettingsPage::class)->call('addSuggestedCategories');
        $this->assertSame($n, ItemCategory::count()); // nothing twice
    }

    public function test_restaurant_and_other(): void
    {
        Livewire::actingAs($this->owner)->test(SettingsPage::class)->call('chooseShopType', 'restaurant');
        $this->assertTrue(Sales::on('tables'));
        $this->assertFalse(Sales::on('advance'));
        $this->assertSame('kg', Sales::weightUnit());

        // "Other" keeps whatever switches are set
        Livewire::actingAs($this->owner)->test(SettingsPage::class)->call('chooseShopType', 'other');
        $this->assertSame('other', Sales::shopType());
        $this->assertTrue(Sales::on('tables'));

        $manager = User::factory()->create(['role' => 'manager']);
        Livewire::actingAs($manager)->test(SettingsPage::class)->assertForbidden();
    }

    public function test_developer_page_and_credit(): void
    {
        $this->get('/developer')->assertOk()->assertSee('MANORANJAN')->assertSee('manoranjan.dev')->assertSee('github.com/manoranjan2050');
        $this->get('/login')->assertSee('Developed by')->assertSee(route('developer'));
        $this->actingAs($this->owner)->get('/')->assertSee('Developed by');
        $this->get('/settings')->assertSee('About this software');
    }
}
