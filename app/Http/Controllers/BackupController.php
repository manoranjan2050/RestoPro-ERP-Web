<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Services\Backup\DropboxDestination;
use App\Services\Backup\GoogleDriveDestination;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class BackupController extends Controller
{
    public function download(Backup $backup)
    {
        Gate::authorize('manage-backups');
        abort_unless($backup->exists(), 404);

        return response()->download($backup->path(), $backup->filename, ['Content-Type' => 'application/zip']);
    }

    /** Start OAuth for Dropbox (PKCE) or Google Drive. */
    public function connect(Request $request, string $provider)
    {
        Gate::authorize('manage-backups');
        $state = Str::random(40);
        $request->session()->put('backup_oauth', ['provider' => $provider, 'state' => $state]);
        $redirect = route('backup.callback', $provider);

        if ($provider === 'dropbox') {
            abort_unless(Setting::get('backup_dropbox_app_key'), 422, 'Save the Dropbox App key first.');
            $verifier = Str::random(64);
            $request->session()->put('backup_oauth.verifier', $verifier);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

            return redirect()->away(DropboxDestination::authorizeUrl($redirect, $state, $challenge));
        }
        if ($provider === 'gdrive') {
            abort_unless(Setting::get('backup_gdrive_client_id') && Setting::secret('backup_gdrive_client_secret'), 422, 'Save the Google client ID and secret first.');

            return redirect()->away(GoogleDriveDestination::authorizeUrl($redirect, $state));
        }
        abort(404);
    }

    public function callback(Request $request, string $provider)
    {
        Gate::authorize('manage-backups');
        $saved = $request->session()->pull('backup_oauth');
        $back = redirect()->route('backup', ['tab' => 'cloud']);

        if (! $saved || $saved['provider'] !== $provider || ! hash_equals($saved['state'], (string) $request->query('state'))) {
            return $back->with('backup_flash', 'Connection expired or was tampered with — please try again.');
        }
        if ($request->query('error')) {
            return $back->with('backup_flash', 'Connection cancelled.');
        }

        try {
            $redirect = route('backup.callback', $provider);
            match ($provider) {
                'dropbox' => DropboxDestination::finishConnect((string) $request->query('code'), $saved['verifier'] ?? '', $redirect),
                'gdrive' => GoogleDriveDestination::finishConnect((string) $request->query('code'), $redirect),
                default => abort(404),
            };
        } catch (\Throwable $e) {
            report($e);

            return $back->with('backup_flash', 'Could not connect: '.Str::limit($e->getMessage(), 160));
        }

        return $back->with('backup_flash', ($provider === 'dropbox' ? 'Dropbox' : 'Google Drive').' connected ✓');
    }
}
