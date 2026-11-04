<?php

namespace Tests\Feature;

use App\Livewire\BackupPage;
use App\Models\Backup;
use App\Models\CashCollection;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Backup\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->owner = User::factory()->create(['role' => 'owner', 'username' => 'owner']);
        $this->actingAs($this->owner);

        CashCollection::create(['date' => '2026-10-01', 'cash' => 5000]);
        $v = Vendor::create(['name' => 'Gopal Dairy']);
        Expense::create(['date' => '2026-10-01', 'category_id' => ExpenseCategory::value('id'), 'vendor_id' => $v->id, 'amount' => 900, 'direct_paid' => 0]);
        $v->payments()->create(['date' => '2026-10-02', 'amount' => 400, 'payment_mode' => 'cash']);
        Staff::create(['name' => 'Raju', 'joining_date' => '2026-10-01', 'salary_type' => 'monthly', 'salary_amount' => 9000]);
        Storage::disk('public')->put('staff/raju.jpg', 'JPEGDATA');
        Storage::disk('local')->put('bills/2026-10/bill1.jpg', 'BILLDATA');
        Setting::put('business_name', 'Dengapol');
    }

    private function zipEntries(string $path): array
    {
        $z = new ZipArchive;
        $z->open($path);
        $names = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $names[] = $z->getNameIndex($i);
        }
        $z->close();
        sort($names);

        return $names;
    }

    public function test_backup_zip_contains_data_files_and_manifest(): void
    {
        Setting::putSecret('backup_github_token', 'ghp_secret');
        $b = BackupService::create('manual', $this->owner->id, upload: false);

        $this->assertTrue($b->exists());
        $this->assertSame(['data.json', 'files/private/bills/2026-10/bill1.jpg', 'files/public/staff/raju.jpg', 'manifest.json'], $this->zipEntries($b->path()));
        $this->assertSame(1, $b->counts['cash_collections']);
        $this->assertSame(1, $b->counts['vendor_payment_allocations']);
        $this->assertSame(hash_file('sha256', $b->path()), $b->sha256);

        $m = BackupService::inspect($b->path());
        $this->assertSame('Dengapol', $m['business']);
        $this->assertSame(2, $m['files']);

        // Server-specific secrets never travel inside a backup.
        $z = new ZipArchive;
        $z->open($b->path());
        $this->assertStringNotContainsString('backup_github_token', $z->getFromName('data.json'));
        $z->close();
    }

    public function test_restore_round_trip_with_safety_copy(): void
    {
        $b = BackupService::create('manual', $this->owner->id, upload: false);

        // Change everything after the backup.
        CashCollection::query()->delete();
        CashCollection::create(['date' => '2026-11-01', 'cash' => 1]);
        Vendor::first()->update(['name' => 'Changed']);
        Staff::create(['name' => 'New Person', 'joining_date' => '2026-11-01', 'salary_type' => 'daily', 'salary_amount' => 100]);
        Storage::disk('public')->put('staff/new.jpg', 'X');
        Storage::disk('local')->delete('bills/2026-10/bill1.jpg');
        Setting::put('backup_keep', '7'); // local setting must survive a restore

        $safety = BackupService::restore($b->path(), $this->owner->id);

        $this->assertSame('pre-restore', $safety->trigger);
        $this->assertSame(['2026-10-01'], CashCollection::pluck('date')->map(fn ($d) => $d->toDateString())->all());
        $this->assertSame('Gopal Dairy', Vendor::first()->name);
        $this->assertSame(['Raju'], Staff::pluck('name')->all());
        $this->assertSame('partial', Expense::first()->status);
        Storage::disk('public')->assertExists('staff/raju.jpg');
        Storage::disk('public')->assertMissing('staff/new.jpg');
        $this->assertSame('BILLDATA', Storage::disk('local')->get('bills/2026-10/bill1.jpg'));
        $this->assertSame('7', Setting::get('backup_keep'));
        $this->assertSame(2, Backup::count()); // the original + the safety copy survive

        // The safety copy really holds the "changed" state.
        BackupService::restore($safety->path());
        $this->assertSame('Changed', Vendor::first()->name);
    }

    public function test_inspect_rejects_bad_files(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($bad, 'not a zip');
        $this->expectExceptionMessageMatches('/not a valid ZIP/');
        BackupService::inspect($bad);
    }

    public function test_inspect_detects_tampering_newer_versions_and_foreign_zips(): void
    {
        $b = BackupService::create('manual', null, upload: false);

        $tamper = function (callable $fn) use ($b) {
            $copy = tempnam(sys_get_temp_dir(), 'bk').'.zip';
            copy($b->path(), $copy);
            $z = new ZipArchive;
            $z->open($copy);
            $fn($z);
            $z->close();

            return $copy;
        };
        $messages = [];
        foreach ([
            fn ($z) => $z->addFromString('data.json', '{"users":[]}'),
            fn ($z) => $z->addFromString('manifest.json', json_encode(['app' => 'restopro-erp', 'format' => 99])),
            fn ($z) => $z->addFromString('manifest.json', json_encode(['app' => 'other'])),
            function ($z) {
                $m = json_decode($z->getFromName('manifest.json'), true);
                $m['migration'] = '9999_99_99_000000_future';
                $z->addFromString('manifest.json', json_encode($m));
            },
        ] as $fn) {
            try {
                BackupService::inspect($tamper($fn));
                $messages[] = 'ACCEPTED';
            } catch (\RuntimeException $e) {
                $messages[] = $e->getMessage();
            }
        }
        $this->assertStringContainsString('damaged', $messages[0]);
        $this->assertStringContainsString('newer version', $messages[1]);
        $this->assertStringContainsString('not a RestoPro backup', $messages[2]);
        $this->assertStringContainsString('newer database changes', $messages[3]);
    }

    public function test_restore_ignores_path_traversal_entries(): void
    {
        $b = BackupService::create('manual', null, upload: false);
        $z = new ZipArchive;
        $z->open($b->path());
        $z->addFromString('files/public/../../../evil.php', '<?php');
        $z->addFromString('files/public/config/app.php', 'x');
        $z->close();

        BackupService::restore($b->path());
        Storage::disk('public')->assertMissing('config/app.php');
        $this->assertFileDoesNotExist(base_path('evil.php'));
    }

    public function test_prune_keeps_newest(): void
    {
        Setting::put('backup_keep', '2');
        $all = collect(range(1, 4))->map(function ($i) {
            $this->travel($i)->minutes();

            return BackupService::create('manual', null, upload: false);
        });
        $this->assertSame(2, Backup::count());
        $this->assertFalse($all[0]->exists());
        $this->assertTrue($all[3]->exists());
    }

    public function test_dropbox_upload_and_failure_is_recorded_not_thrown(): void
    {
        Setting::put('backup_dropbox_app_key', 'appkey123');
        Setting::putSecret('backup_dropbox_refresh', 'refresh-xyz');
        $this->assertNotSame('refresh-xyz', Setting::get('backup_dropbox_refresh')); // encrypted at rest

        Http::fake([
            'api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'AT']),
            'content.dropboxapi.com/2/files/upload' => Http::response(['name' => 'x']),
            'api.dropboxapi.com/2/files/list_folder' => Http::response(['entries' => []]),
        ]);
        $b = BackupService::create('manual');
        $this->assertTrue($b->destinations['dropbox']['ok']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'files/upload')
            && json_decode($r->header('Dropbox-API-Arg')[0], true)['path'] === '/'.$b->filename);
    }

    public function test_dropbox_failure_is_recorded_and_local_backup_still_made(): void
    {
        Setting::put('backup_dropbox_app_key', 'appkey123');
        Setting::putSecret('backup_dropbox_refresh', 'refresh-xyz');
        Http::fake(['*' => Http::response(['error_description' => 'invalid'], 400)]);
        $b2 = BackupService::create('manual');
        $this->assertFalse($b2->destinations['dropbox']['ok']);
        $this->assertStringContainsString('connect Dropbox again', $b2->destinations['dropbox']['message']);
        $this->assertTrue($b2->exists()); // local backup still made
    }

    public function test_github_refuses_public_repo(): void
    {
        Setting::put('backup_github_repo', 'me/backups');
        Setting::putSecret('backup_github_token', 'tok');

        Http::fake(['api.github.com/repos/me/backups' => Http::response(['private' => false, 'full_name' => 'me/backups'])]);
        $b = BackupService::create('manual');
        $this->assertFalse($b->destinations['github']['ok']);
        $this->assertStringContainsString('PUBLIC', $b->destinations['github']['message']);
    }

    public function test_github_uploads_to_private_repo(): void
    {
        Setting::put('backup_github_repo', 'me/backups');
        Setting::putSecret('backup_github_token', 'tok');
        Http::fake([
            'api.github.com/repos/me/backups' => Http::response(['private' => true, 'full_name' => 'me/backups']),
            'api.github.com/repos/me/backups/contents/backups/*' => Http::response(['content' => []], 201),
            'api.github.com/repos/me/backups/contents/backups?*' => Http::response([]),
        ]);
        $b = BackupService::create('manual');
        $this->assertTrue($b->destinations['github']['ok'], $b->destinations['github']['message']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && base64_decode($r['content']) === file_get_contents($b->path()));
    }

    public function test_google_drive_resumable_upload(): void
    {
        Setting::put('backup_gdrive_client_id', 'x.apps.googleusercontent.com');
        Setting::putSecret('backup_gdrive_client_secret', 's');
        Setting::putSecret('backup_gdrive_refresh', 'r');
        Setting::put('backup_gdrive_folder_id', 'FOLDER');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'AT']),
            'www.googleapis.com/upload/drive/v3/files?uploadType=resumable' => Http::response([], 200, ['Location' => 'https://upload.example/session1']),
            'upload.example/session1' => Http::response(['id' => 'F1'], 200),
            'www.googleapis.com/drive/v3/files*' => Http::response(['files' => []]),
        ]);
        $b = BackupService::create('manual');
        $this->assertTrue($b->destinations['gdrive']['ok'], $b->destinations['gdrive']['message']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'uploadType=resumable') && $r['parents'] === ['FOLDER']);
    }

    public function test_page_owner_only_backup_now_download_and_restore_upload(): void
    {
        $this->get('/backup')->assertOk()->assertSee('Back up now');

        Livewire::test(BackupPage::class)->call('backupNow');
        $b = Backup::sole();
        $this->get(route('backup.download', $b))->assertOk()->assertDownload($b->filename);

        // Upload the ZIP back and restore it through the page.
        CashCollection::query()->delete();
        $file = UploadedFile::fake()->createWithContent('backup.zip', file_get_contents($b->path()));
        Livewire::test(BackupPage::class)
            ->set('restoreFile', $file)
            ->assertSet('preview.business', 'Dengapol')
            ->call('restore')->assertHasErrors('confirmText')
            ->set('confirmText', 'restore')->call('restore')
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(1, CashCollection::count());

        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)->get('/backup')->assertForbidden();
        $this->actingAs($manager)->get(route('backup.download', $b))->assertForbidden();
    }

    public function test_cloud_settings_saved_encrypted_and_oauth_state_checked(): void
    {
        Livewire::test(BackupPage::class)
            ->set('cfg.github.repo', 'not a repo')->call('saveCloud', 'github')->assertHasErrors('cfg.github.repo')
            ->set('cfg.github.repo', 'me/backups')->set('cfg.github.token', 'github_pat_ABC')->call('saveCloud', 'github')->assertHasNoErrors();
        $this->assertSame('github_pat_ABC', Setting::secret('backup_github_token'));
        $this->assertStringNotContainsString('github_pat_ABC', (string) Setting::get('backup_github_token'));

        Livewire::test(BackupPage::class)->set('cfg.dropbox.app_key', 'abc123')->call('saveCloud', 'dropbox')->assertHasNoErrors();
        $res = $this->get(route('backup.connect', 'dropbox'));
        $this->assertStringStartsWith('https://www.dropbox.com/oauth2/authorize?', $res->headers->get('Location'));
        $this->assertStringContainsString('code_challenge_method=S256', $res->headers->get('Location'));

        $this->get(route('backup.callback', 'dropbox').'?state=WRONG&code=x')
            ->assertRedirect(route('backup', ['tab' => 'cloud']))->assertSessionHas('backup_flash');
        $this->assertNull(Setting::secret('backup_dropbox_refresh'));
    }

    public function test_scheduled_command(): void
    {
        $this->artisan('restopro:backup --no-upload')->assertSuccessful();
        $this->assertSame('scheduled', Backup::sole()->trigger);
    }
}
