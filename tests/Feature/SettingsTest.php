<?php

namespace Tests\Feature;

use App\Livewire\SettingsPage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => 'owner', 'username' => 'owner']);
    }

    public function test_owner_saves_business_details_and_logo(): void
    {
        Storage::fake('public');
        $this->actingAs($this->owner)->get('/settings')->assertOk()->assertSee('Business details');

        Livewire::actingAs($this->owner)->test(SettingsPage::class)
            ->set('biz.business_name', 'Hotel Sagar')
            ->set('biz.business_address', 'Station Road, Jajpur')
            ->set('biz.business_gstin', '21abcde1234f1z5')
            ->set('logo', UploadedFile::fake()->image('logo.png', 200, 200))
            ->call('saveBusiness')
            ->assertHasNoErrors();

        $this->assertSame('Hotel Sagar', Setting::get('business_name'));
        $this->assertSame('21ABCDE1234F1Z5', Setting::get('business_gstin'));
        Storage::disk('public')->assertExists(Setting::get('business_logo'));
        $this->get('/')->assertSee('Hotel Sagar')->assertSee(route('media', Setting::get('business_logo')));

        // PDF header carries the address
        $this->get('/reports/monthly.pdf?month='.now()->format('Y-m'))->assertOk();

        Livewire::actingAs($this->owner)->test(SettingsPage::class)->call('removeLogo');
        $this->assertNull(Setting::get('business_logo'));
    }

    public function test_validation_and_money_settings(): void
    {
        Livewire::actingAs($this->owner)->test(SettingsPage::class)
            ->set('biz.business_name', '')
            ->set('biz.business_gstin', 'BAD')
            ->set('biz.business_fssai', '123')
            ->call('saveBusiness')
            ->assertHasErrors(['biz.business_name', 'biz.business_gstin', 'biz.business_fssai'])
            ->set('fy_start_month', '1')
            ->set('currency_symbol', 'Rs')
            ->call('saveMoney')
            ->assertHasNoErrors();

        $this->assertSame('1', Setting::get('fy_start_month'));
        $this->assertSame('Rs', Setting::get('currency_symbol'));
    }

    public function test_manager_cannot_open_settings_and_does_not_see_it_in_menu(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)->get('/settings')->assertForbidden();
        $this->actingAs($manager)->get('/')->assertDontSee(route('settings'))->assertDontSee(route('backup'));
        $this->actingAs($this->owner)->get('/')->assertSee(route('settings'));
    }

    public function test_owner_adds_manager_who_can_sign_in(): void
    {
        Livewire::actingAs($this->owner)->test(SettingsPage::class)
            ->call('newUser')
            ->set('u.name', 'Ravi Kumar')
            ->set('u.username', 'Ravi')
            ->set('u.mobile', '+91 98765-43210')
            ->set('u.password', 'secret-pass-1')
            ->call('saveUser')
            ->assertHasNoErrors();

        $ravi = User::where('username', 'ravi')->firstOrFail();
        $this->assertSame(['manager', '9876543210'], [$ravi->role, $ravi->mobile]);
        $this->assertTrue(Hash::check('secret-pass-1', $ravi->password));

        $this->postJson('/api/v1/auth/login', ['identifier' => '9876543210', 'password' => 'secret-pass-1', 'device_name' => 't'])->assertOk();
    }

    public function test_turning_off_or_resetting_signs_the_user_out(): void
    {
        $m = User::factory()->create(['role' => 'manager', 'username' => 'mgr']);
        $m->createToken('phone');

        Livewire::actingAs($this->owner)->test(SettingsPage::class)
            ->call('editUser', $m->id)
            ->set('u.password', 'new-pass-123')
            ->call('saveUser')->assertHasNoErrors();
        $this->assertSame(0, $m->tokens()->count());
        $this->assertTrue(Hash::check('new-pass-123', $m->fresh()->password));

        $m->createToken('phone');
        Livewire::actingAs($this->owner)->test(SettingsPage::class)
            ->call('editUser', $m->id)->set('u.is_active', false)->call('saveUser')->assertHasNoErrors();
        $this->assertFalse($m->fresh()->is_active);
        $this->assertSame(0, $m->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['identifier' => 'mgr', 'password' => 'new-pass-123', 'device_name' => 't'])->assertStatus(422);
    }

    public function test_cannot_lock_yourself_out_or_remove_the_last_owner(): void
    {
        Livewire::actingAs($this->owner)->test(SettingsPage::class)
            ->call('editUser', $this->owner->id)->set('u.role', 'manager')->call('saveUser')
            ->assertHasErrors('u.role');
        $this->assertSame('owner', $this->owner->fresh()->role);

        $other = User::factory()->create(['role' => 'owner']);
        Livewire::actingAs($other)->test(SettingsPage::class)
            ->call('editUser', $this->owner->id)->set('u.is_active', false)->call('saveUser')
            ->assertHasNoErrors(); // another active owner remains
        $this->assertFalse($this->owner->fresh()->is_active);
    }
}
