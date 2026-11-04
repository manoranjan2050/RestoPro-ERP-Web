<?php

namespace App\Livewire;

use App\Models\Backup;
use App\Models\Setting;
use App\Services\Backup\BackupService;
use App\Services\Backup\Destinations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Backup & Sync')]
class BackupPage extends Component
{
    use WithFileUploads;

    #[Url]
    public string $tab = 'backups'; // backups | cloud | schedule

    // Restore
    public ?int $restoreId = null;
    public $restoreFile;
    public ?array $preview = null;
    public string $confirmText = '';

    // Cloud settings forms
    public array $cfg = [];
    public array $testResult = [];

    // Schedule
    public bool $auto = true;
    public string $time = '02:00';
    public int $keep = 14;
    public int $keepCloud = 30;

    public function mount(): void
    {
        Gate::authorize('manage-backups');
        $this->loadSettings();
        if ($msg = session('backup_flash')) {
            $this->dispatch('toast', message: $msg);
        }
    }

    // ---------------------------------------------------------------- backups

    public function backupNow(): void
    {
        Gate::authorize('manage-backups');
        $b = BackupService::create('manual', Auth::id());
        $failed = collect($b->destinations)->filter(fn ($d) => ! $d['ok'])->count();
        $this->dispatch('toast', message: 'Backup created · '.$b->sizeLabel().($failed ? " · {$failed} upload(s) failed" : ''));
    }

    public function reupload(int $id): void
    {
        Gate::authorize('manage-backups');
        $b = Backup::findOrFail($id);
        abort_unless($b->exists(), 404);
        BackupService::upload($b);
        $this->dispatch('toast', message: 'Upload retried');
    }

    public function deleteBackup(int $id): void
    {
        Gate::authorize('manage-backups');
        Backup::findOrFail($id)->delete();
        $this->dispatch('toast', message: 'Backup deleted from this server');
    }

    // ---------------------------------------------------------------- restore

    public function chooseRestore(int $id): void
    {
        $b = Backup::findOrFail($id);
        $this->resetRestore();
        try {
            $this->preview = BackupService::inspect($b->path()) + ['source' => $b->filename];
            $this->restoreId = $b->id;
        } catch (\Throwable $e) {
            $this->addError('restore', $e->getMessage());
        }
    }

    public function updatedRestoreFile(): void
    {
        $this->validate(['restoreFile' => ['required', 'file', 'max:204800']], [], ['restoreFile' => 'backup file']);
        $this->preview = null;
        $this->restoreId = null;
        try {
            $this->preview = BackupService::inspect($this->restoreFile->getRealPath()) + ['source' => $this->restoreFile->getClientOriginalName()];
        } catch (\Throwable $e) {
            $this->addError('restoreFile', $e->getMessage());
        }
    }

    public function restore()
    {
        Gate::authorize('manage-backups');
        if (mb_strtoupper(trim($this->confirmText)) !== 'RESTORE') {
            $this->addError('confirmText', 'Type RESTORE to confirm.');

            return null;
        }
        $path = $this->restoreId ? Backup::findOrFail($this->restoreId)->path() : $this->restoreFile?->getRealPath();
        if (! $path) {
            return null;
        }
        try {
            $safety = BackupService::restore($path, Auth::id());
        } catch (\Throwable $e) {
            report($e);
            $this->addError('restore', 'Restore failed, nothing was changed: '.$e->getMessage());

            return null;
        }

        // The users table was replaced too — sign in again with the restored accounts.
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        session()->flash('status', 'Restore complete. A safety copy of the previous data was saved ('.$safety->filename.'). Sign in again.');

        return $this->redirectRoute('login');
    }

    public function resetRestore(): void
    {
        $this->reset('restoreId', 'restoreFile', 'preview', 'confirmText');
        $this->resetValidation();
    }

    // ------------------------------------------------------------------ cloud

    public function saveCloud(string $key): void
    {
        Gate::authorize('manage-backups');
        $c = $this->cfg[$key] ?? [];
        match ($key) {
            'dropbox' => $this->validate(['cfg.dropbox.app_key' => ['required', 'alpha_num', 'max:40']], [], ['cfg.dropbox.app_key' => 'App key']),
            'gdrive' => $this->validate([
                'cfg.gdrive.client_id' => ['required', 'string', 'max:200', 'ends_with:.apps.googleusercontent.com'],
                'cfg.gdrive.client_secret' => [Setting::secret('backup_gdrive_client_secret') ? 'nullable' : 'required', 'string', 'max:200'],
            ], [], ['cfg.gdrive.client_id' => 'Client ID', 'cfg.gdrive.client_secret' => 'Client secret']),
            'github' => $this->validate([
                'cfg.github.repo' => ['required', 'regex:#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#'],
                'cfg.github.branch' => ['nullable', 'regex:#^[A-Za-z0-9_./-]+$#', 'max:100'],
                'cfg.github.path' => ['nullable', 'regex:#^[A-Za-z0-9_./-]+$#', 'max:100'],
                'cfg.github.token' => [Setting::secret('backup_github_token') ? 'nullable' : 'required', 'string', 'max:255'],
            ], ['cfg.github.repo.regex' => 'Use the form owner/repository.'], ['cfg.github.repo' => 'repository', 'cfg.github.token' => 'token']),
        };

        if ($key === 'dropbox') {
            if (Setting::get('backup_dropbox_app_key') !== $c['app_key']) {
                Setting::putSecret('backup_dropbox_refresh', null); // new app → must reconnect
            }
            Setting::put('backup_dropbox_app_key', trim($c['app_key']));
        } elseif ($key === 'gdrive') {
            Setting::put('backup_gdrive_client_id', trim($c['client_id']));
            if (! empty($c['client_secret'])) {
                Setting::putSecret('backup_gdrive_client_secret', trim($c['client_secret']));
            }
        } else {
            Setting::put('backup_github_repo', trim($c['repo']));
            Setting::put('backup_github_branch', trim($c['branch'] ?? '') ?: 'main');
            Setting::put('backup_github_path', trim($c['path'] ?? '') ?: 'backups');
            if (! empty($c['token'])) {
                Setting::putSecret('backup_github_token', trim($c['token']));
            }
        }
        $this->loadSettings();
        $this->dispatch('toast', message: 'Saved');
    }

    public function testCloud(string $key): void
    {
        Gate::authorize('manage-backups');
        try {
            $this->testResult[$key] = ['ok' => true, 'message' => Destinations::get($key)->test()];
        } catch (\Throwable $e) {
            $this->testResult[$key] = ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function toggleCloud(string $key): void
    {
        Gate::authorize('manage-backups');
        Setting::put("backup_{$key}_enabled", Setting::get("backup_{$key}_enabled", '1') === '1' ? '0' : '1');
    }

    public function disconnect(string $key): void
    {
        Gate::authorize('manage-backups');
        foreach (match ($key) {
            'dropbox' => ['backup_dropbox_refresh'],
            'gdrive' => ['backup_gdrive_refresh', 'backup_gdrive_folder_id'],
            'github' => ['backup_github_token'],
            default => [],
        } as $s) {
            Setting::put($s, null);
        }
        cache()->forget($key === 'dropbox' ? 'dropbox.token' : 'gdrive.token');
        unset($this->testResult[$key]);
        $this->loadSettings();
        $this->dispatch('toast', message: 'Disconnected');
    }

    // --------------------------------------------------------------- schedule

    public function saveSchedule(): void
    {
        Gate::authorize('manage-backups');
        $this->validate([
            'time' => ['required', 'date_format:H:i'],
            'keep' => ['required', 'integer', 'between:1,365'],
            'keepCloud' => ['required', 'integer', 'between:1,365'],
        ]);
        Setting::put('backup_auto', $this->auto ? '1' : '0');
        Setting::put('backup_time', $this->time);
        Setting::put('backup_keep', (string) $this->keep);
        Setting::put('backup_keep_cloud', (string) $this->keepCloud);
        $this->dispatch('toast', message: 'Schedule saved');
    }

    public function render()
    {
        $backups = Backup::with('creator:id,name')->latest()->latest('id')->get();
        $dests = Destinations::all();

        return view('livewire.backup-page', [
            'backups' => $backups,
            'dests' => $dests,
            'connected' => collect($dests)->map(fn ($d) => $d->configured())->all(),
            'enabled' => collect($dests)->keys()->mapWithKeys(fn ($k) => [$k => Setting::get("backup_{$k}_enabled", '1') === '1'])->all(),
            'lastAt' => Setting::get('backup_last_at'),
            'callback' => fn (string $p) => route('backup.callback', $p),
            'cronLine' => 'cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
        ]);
    }

    private function loadSettings(): void
    {
        $this->cfg = [
            'dropbox' => ['app_key' => (string) Setting::get('backup_dropbox_app_key')],
            'gdrive' => ['client_id' => (string) Setting::get('backup_gdrive_client_id'), 'client_secret' => ''],
            'github' => [
                'repo' => (string) Setting::get('backup_github_repo'), 'branch' => (string) (Setting::get('backup_github_branch') ?: 'main'),
                'path' => (string) (Setting::get('backup_github_path') ?: 'backups'), 'token' => '',
            ],
        ];
        $this->auto = Setting::get('backup_auto', '1') === '1';
        $this->time = Setting::get('backup_time', '02:00');
        $this->keep = (int) Setting::get('backup_keep', '14');
        $this->keepCloud = (int) Setting::get('backup_keep_cloud', '30');
    }
}
