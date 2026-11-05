<?php

namespace Tests\Feature;

use App\Livewire\SettingsPage;
use App\Models\User;
use App\Support\AndroidApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AppDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_uploads_apk_and_staff_download_it(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->get(route('app.download'))->assertRedirect(route('login')); // signed out → sign in first
        $this->actingAs($owner)->get('/')->assertDontSee('Get the Android app');
        $this->get(route('app.download'))->assertNotFound();

        Livewire::actingAs($owner)->test(SettingsPage::class)
            ->set('apk', UploadedFile::fake()->create('notes.pdf', 10))->set('apkVersion', '2.0.1')->call('uploadApk')->assertHasErrors('apk')
            ->set('apk', UploadedFile::fake()->create('app-release.apk', 2200, 'application/vnd.android.package-archive'))->set('apkVersion', '')->call('uploadApk')->assertHasErrors('apkVersion')
            ->set('apkVersion', '2.0.1')->call('uploadApk')->assertHasNoErrors();

        $this->assertSame('2.0.1', AndroidApp::info()['version']);
        $this->get('/')->assertSee('Get the Android app')->assertSee('v2.0.1');
        $res = $this->get(route('app.download'));
        $res->assertOk()->assertHeader('content-type', 'application/vnd.android.package-archive');
        $this->assertStringContainsString('RestoPro-ERP-2.0.1.apk', $res->headers->get('content-disposition'));

        // a cashier can download it (from Profile), but only the owner can upload
        $cashier = User::factory()->create(['role' => 'cashier']);
        $this->actingAs($cashier)->get(route('app.download'))->assertOk();
        $this->get('/profile')->assertSee('Download APK');
        Livewire::actingAs(User::factory()->create(['role' => 'manager']))->test(SettingsPage::class)->assertForbidden();

        Livewire::actingAs($owner)->test(SettingsPage::class)->call('removeApk');
        $this->assertNull(AndroidApp::info());
    }
}
