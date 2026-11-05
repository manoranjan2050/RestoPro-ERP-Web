<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Profile;
use App\Livewire\SettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class DemoAccountTest extends TestCase
{
    use RefreshDatabase;

    private function demo(): User
    {
        config(['restopro.demo.username' => 'demo', 'restopro.demo.password' => 'demo']);

        return User::factory()->create(['username' => 'demo', 'name' => 'Demo', 'role' => 'owner', 'password' => Hash::make('demo')]);
    }

    public function test_login_page_offers_demo_button_only_when_configured(): void
    {
        $this->get('/login')->assertDontSee('Use the demo login');
        $this->demo();
        $this->get('/login')->assertSee('Use the demo login')->assertSee('demo');

        Livewire::test(Login::class)->call('useDemo')->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_demo_account_details_and_password_are_locked(): void
    {
        $demo = $this->demo();

        Livewire::actingAs($demo)->test(Profile::class)
            ->set('name', 'Hacker')->set('username', 'hacked')->call('saveProfile')
            ->set('current_password', 'demo')->set('new_password', 'newpassword1')->set('new_password_confirmation', 'newpassword1')->call('changePassword');
        $demo->refresh();
        $this->assertSame('demo', $demo->username);
        $this->assertSame('Demo', $demo->name);
        $this->assertTrue(Hash::check('demo', $demo->password));

        // another owner can't change it from Settings → Logins either
        $owner = User::factory()->create(['role' => 'owner']);
        Livewire::actingAs($owner)->test(SettingsPage::class)
            ->call('editUser', $demo->id)->set('u.password', 'newpassword1')->set('u.role', 'cashier')->call('saveUser')
            ->assertHasErrors('u.username');
        $demo->refresh();
        $this->assertSame('owner', $demo->role);
        $this->assertTrue(Hash::check('demo', $demo->password));

        // model guard: no direct change or delete either
        $this->expectException(\DomainException::class);
        $demo->update(['password' => Hash::make('x')]);
    }

    public function test_normal_accounts_unaffected(): void
    {
        $this->demo();
        $u = User::factory()->create(['username' => 'ravi']);
        $u->update(['name' => 'Ravi K']);
        $this->assertSame('Ravi K', $u->fresh()->name);
    }
}
