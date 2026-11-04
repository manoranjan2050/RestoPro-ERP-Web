<?php

namespace Tests\Feature;

use App\Livewire\Sales\Items;
use App\Livewire\Sales\Pos;
use App\Livewire\SettingsPage;
use App\Models\Item;
use App\Models\Setting;
use App\Models\User;
use App\Support\Sales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WeightUnitTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner']);
        Setting::put('app_mode', 'advanced');
    }

    public function test_setting_switches_between_kg_pound_and_both(): void
    {
        $this->assertSame('kg', Sales::weightUnit());
        $this->assertSame(['kg'], array_keys(Sales::sizeSets()));

        Livewire::actingAs($this->owner)->test(SettingsPage::class)->set('weight_unit', 'lb')->call('saveBilling')->assertHasNoErrors();
        $this->assertSame('lb', Setting::get('weight_unit'));
        $this->assertSame(['lb' => ['0.5 lb', '1 lb', '1.5 lb', '2 lb', '3 lb']], Sales::sizeSets());

        Livewire::actingAs($this->owner)->test(SettingsPage::class)->set('weight_unit', 'both')->call('saveBilling');
        $this->assertSame(['kg', 'lb'], array_keys(Sales::sizeSets()));

        Livewire::actingAs($this->owner)->test(SettingsPage::class)->set('weight_unit', 'stone')->call('saveBilling')->assertHasErrors('weight_unit');

        $token = $this->owner->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/meta')->assertJsonPath('sales.weight_unit', 'both')->assertJsonPath('sales.sizes.lb.1', '1 lb');
    }

    public function test_quick_pound_sizes_on_an_item(): void
    {
        Setting::put('weight_unit', 'lb');
        Livewire::actingAs($this->owner)->test(Items::class)
            ->call('create')->set('f.name', 'Pineapple Cake')
            ->call('addVariant') // an empty "other size" row is dropped
            ->call('addSizes', 'lb')->assertCount('variants', 5)
            ->call('addSizes', 'lb')->assertCount('variants', 5) // no duplicates
            ->call('removeVariant', 4)->call('removeVariant', 2)                // keep 0.5 / 1 / 2 lb
            ->set('variants.0.price', '350')->set('variants.1.price', '650')->set('variants.2.price', '1250')
            ->call('save')->assertHasNoErrors();

        $item = Item::with('variants')->sole();
        $this->assertSame(['0.5 lb', '1 lb', '2 lb'], $item->variants->pluck('name')->all());
        $this->actingAs($this->owner)->get(route('pos'))->assertSee('₹350 – ₹1,250');
    }

    public function test_pound_unit_and_weight_picker(): void
    {
        Setting::put('weight_unit', 'both');
        Item::create(['name' => 'Rasgulla', 'unit' => 'lb', 'price' => 200]);
        $this->assertArrayHasKey('lb', Item::UNITS);

        Livewire::actingAs($this->owner)->test(Pos::class)->call('setType', 'advance')
            ->assertSee('0.5 lb')->assertSee('500 g')
            ->set('details.weight', '1 lb')->assertSet('details.weight', '1 lb');
    }
}
