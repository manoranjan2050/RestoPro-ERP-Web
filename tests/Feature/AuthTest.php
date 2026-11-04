<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create([
            'username' => 'owner', 'email' => 'owner@resto.test', 'mobile' => '9876543210', 'password' => 'secret-pass-1',
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Mobile, username or email');
    }

    public static function identifiers(): array
    {
        return [
            'username' => ['owner'],
            'username mixed case' => ['Owner'],
            'email' => ['owner@resto.test'],
            'mobile' => ['9876543210'],
            'mobile with +91 and spaces' => ['+91 98765 43210'],
            'mobile with leading 0' => ['09876543210'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('identifiers')]
    public function test_login_with_any_identifier(string $identifier): void
    {
        $user = $this->owner();

        Livewire::test(Login::class)
            ->set('identifier', $identifier)
            ->set('password', 'secret-pass-1')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected_and_rate_limited(): void
    {
        $this->owner();

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)->set('identifier', 'owner')->set('password', 'nope')->call('login')
                ->assertHasErrors(['identifier']);
        }

        Livewire::test(Login::class)->set('identifier', 'owner')->set('password', 'secret-pass-1')->call('login')
            ->assertHasErrors(['identifier']);
        $this->assertGuest();
    }

    public function test_disabled_user_cannot_login(): void
    {
        $u = $this->owner();
        $u->update(['is_active' => false]);

        Livewire::test(Login::class)->set('identifier', 'owner')->set('password', 'secret-pass-1')->call('login')
            ->assertHasErrors(['identifier']);
        $this->assertGuest();
    }

    public function test_dashboard_and_profile_render_for_owner(): void
    {
        $this->actingAs($this->owner());
        $this->get('/')->assertOk()->assertSee('Today');
        $this->get('/profile')->assertOk()->assertSee('Profile details');
    }

    public function test_profile_update_with_photo(): void
    {
        Storage::fake('public');
        $user = $this->owner();
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('name', 'Ravi Kumar')
            ->set('username', 'Ravi_K')
            ->set('mobile', '+91 91234 56789')
            ->set('photo', UploadedFile::fake()->image('me.jpg', 300, 300))
            ->call('saveProfile')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertSame('ravi_k', $user->username);
        $this->assertSame('9123456789', $user->mobile);
        $this->assertNotNull($user->photo_path);
        Storage::disk('public')->assertExists($user->photo_path);
        $this->get(route('media', $user->photo_path))->assertOk();
    }

    public function test_media_route_rejects_path_traversal(): void
    {
        $this->get('/media/../.env')->assertNotFound();
        $this->get('/media/private/x.jpg')->assertNotFound();
    }

    public function test_change_password_requires_current(): void
    {
        $user = $this->owner();
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('current_password', 'wrong')->set('new_password', 'new-pass-123')->set('new_password_confirmation', 'new-pass-123')
            ->call('changePassword')->assertHasErrors(['current_password']);

        Livewire::test(Profile::class)
            ->set('current_password', 'secret-pass-1')->set('new_password', 'new-pass-123')->set('new_password_confirmation', 'new-pass-123')
            ->call('changePassword')->assertHasNoErrors();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-pass-123', $user->fresh()->password));
    }
}
