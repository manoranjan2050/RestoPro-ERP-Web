<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Managers can add and edit entries; only the owner can delete.
        Gate::define('delete-records', fn (User $user) => $user->isOwner());
        // Backups hold every record, password hashes and bill photos — owner only.
        Gate::define('manage-backups', fn (User $user) => $user->isOwner());
        Gate::define('manage-settings', fn (User $user) => $user->isOwner());

        // Backup ZIPs can be larger than Livewire's 12 MB default. Each form still validates its own max size.
        config([
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:204800'],
            'livewire.temporary_file_upload.max_upload_time' => 15,
        ]);
    }
}
