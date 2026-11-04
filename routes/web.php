<?php

use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
});

Route::middleware('auth')->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/profile', Profile::class)->name('profile');

    Route::get('/collections', \App\Livewire\Collections::class)->name('collections');
    Route::get('/revenue', \App\Livewire\RevenueLog::class)->name('revenue');
    Route::get('/revenue/export', \App\Http\Controllers\RevenueExportController::class)->name('revenue.export');

    Route::get('/expenses', \App\Livewire\Expenses::class)->name('expenses');
    Route::get('/vendors', \App\Livewire\Vendors::class)->name('vendors');
    Route::get('/vendors/{vendor}', \App\Livewire\VendorKhata::class)->name('vendors.khata');

    Route::get('/staff', \App\Livewire\StaffList::class)->name('staff');
    Route::get('/staff/{staff}', \App\Livewire\StaffProfile::class)->name('staff.show');
    Route::get('/attendance', \App\Livewire\AttendanceRegister::class)->name('attendance');

    Route::get('/ledger', \App\Livewire\MasterLedgerPage::class)->name('ledger');
    Route::get('/ledger/export', \App\Http\Controllers\LedgerExportController::class)->name('ledger.export');

    Route::get('/reports', \App\Livewire\Reports::class)->name('reports');
    Route::get('/analytics', \App\Livewire\AnnualSummary::class)->name('analytics');

    Route::get('/backup', \App\Livewire\BackupPage::class)->name('backup');
    Route::get('/settings', \App\Livewire\SettingsPage::class)->name('settings');
    Route::get('/backup/{backup}/download', [\App\Http\Controllers\BackupController::class, 'download'])->name('backup.download');
    Route::get('/backup/connect/{provider}', [\App\Http\Controllers\BackupController::class, 'connect'])
        ->where('provider', 'dropbox|gdrive')->name('backup.connect');
    Route::get('/backup/callback/{provider}', [\App\Http\Controllers\BackupController::class, 'callback'])
        ->where('provider', 'dropbox|gdrive')->name('backup.callback');
    Route::get('/reports/{type}.{format}', \App\Http\Controllers\ReportDownloadController::class)
        ->where(['type' => '[a-z_]+', 'format' => 'pdf|xlsx'])->name('reports.download');

    // Bill photos live on the private disk; only signed-in users can open them.
    Route::get('/expenses/{expense}/bill', function (\App\Models\Expense $expense) {
        $disk = Storage::disk(\App\Models\Expense::BILL_DISK);
        abort_unless($expense->bill_path && $disk->exists($expense->bill_path), 404);

        return $disk->response($expense->bill_path, 'bill-'.$expense->date->format('Y-m-d').'-'.$expense->id.'.'.pathinfo($expense->bill_path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    })->name('expenses.bill');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});

// Public read-only staff statement — no login. Token is unguessable; optional PIN inside.
Route::get('/s/{token}', \App\Livewire\StaffShare::class)
    ->where('token', '[A-Za-z0-9]{20,64}')
    ->middleware(['throttle:60,1', \App\Http\Middleware\NoIndex::class])
    ->name('staff.share');

/*
 * Serves uploaded public images (avatars, logos, staff photos) straight from storage.
 * Avoids relying on `storage:link`, which can be blocked on shared hosting / Windows.
 */
Route::get('/media/{path}', function (string $path) {
    abort_unless(preg_match('#^(avatars|logos|staff)/[A-Za-z0-9._-]+$#', $path), 404);
    abort_unless(Storage::disk('public')->exists($path), 404);

    return Storage::disk('public')->response($path, null, [
        'Cache-Control' => 'public, max-age=604800',
    ]);
})->where('path', '.*')->name('media');
