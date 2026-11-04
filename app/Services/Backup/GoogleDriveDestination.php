<?php

namespace App\Services\Backup;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Drive with the narrow `drive.file` scope (the app can only see files it created).
 * Backups go into a "RestoPro Backups" folder created on connect.
 * Settings: backup_gdrive_client_id, backup_gdrive_client_secret (enc), backup_gdrive_refresh (enc), backup_gdrive_folder_id.
 */
class GoogleDriveDestination implements Destination
{
    private const API = 'https://www.googleapis.com/drive/v3';

    public function label(): string
    {
        return 'Google Drive';
    }

    public function configured(): bool
    {
        return (bool) (Setting::get('backup_gdrive_client_id') && Setting::secret('backup_gdrive_client_secret')
            && Setting::secret('backup_gdrive_refresh') && Setting::get('backup_gdrive_folder_id'));
    }

    public static function authorizeUrl(string $redirect, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => Setting::get('backup_gdrive_client_id'),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public static function finishConnect(string $code, string $redirect): void
    {
        $r = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => Setting::get('backup_gdrive_client_id'),
            'client_secret' => Setting::secret('backup_gdrive_client_secret'),
            'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
        ]);
        if (! $r->ok() || ! $r->json('refresh_token')) {
            throw new RuntimeException('Google did not return a refresh token: '.($r->json('error_description') ?? $r->json('error') ?? $r->status()));
        }
        Setting::putSecret('backup_gdrive_refresh', $r->json('refresh_token'));
        Cache::forget('gdrive.token');

        $folder = Http::withToken((new self)->token())->timeout(30)->post(self::API.'/files', [
            'name' => 'RestoPro Backups', 'mimeType' => 'application/vnd.google-apps.folder',
        ]);
        if (! $folder->ok()) {
            throw new RuntimeException('Connected, but could not create the backup folder ('.$folder->status().').');
        }
        Setting::put('backup_gdrive_folder_id', $folder->json('id'));
    }

    private function token(): string
    {
        return Cache::remember('gdrive.token', 50 * 60, function () {
            $r = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', [
                'client_id' => Setting::get('backup_gdrive_client_id'),
                'client_secret' => Setting::secret('backup_gdrive_client_secret'),
                'refresh_token' => Setting::secret('backup_gdrive_refresh'),
                'grant_type' => 'refresh_token',
            ]);
            if (! $r->ok() || ! $r->json('access_token')) {
                throw new RuntimeException('Google Drive login expired — connect Google Drive again. ('.($r->json('error') ?? $r->status()).')');
            }

            return $r->json('access_token');
        });
    }

    public function upload(string $localPath, string $filename): void
    {
        // Resumable upload: 1) create the session with metadata, 2) PUT the bytes.
        $session = Http::withToken($this->token())->timeout(30)
            ->withHeaders(['X-Upload-Content-Type' => 'application/zip', 'X-Upload-Content-Length' => (string) filesize($localPath)])
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable', [
                'name' => $filename, 'parents' => [Setting::get('backup_gdrive_folder_id')], 'mimeType' => 'application/zip',
            ]);
        $location = $session->header('Location');
        if (! $session->ok() || ! $location) {
            throw new RuntimeException('Google Drive upload could not start ('.$session->status().'): '.substr($session->body(), 0, 150));
        }
        $put = Http::timeout(300)->withBody(file_get_contents($localPath), 'application/zip')->put($location);
        if (! $put->successful()) {
            throw new RuntimeException('Google Drive upload failed ('.$put->status().')');
        }
    }

    public function prune(int $keep): void
    {
        $folder = Setting::get('backup_gdrive_folder_id');
        $r = Http::withToken($this->token())->timeout(30)->get(self::API.'/files', [
            'q' => "'{$folder}' in parents and trashed = false",
            'orderBy' => 'name desc', 'fields' => 'files(id,name)', 'pageSize' => 500,
        ]);
        if (! $r->ok()) {
            return;
        }
        collect($r->json('files', []))->filter(fn ($f) => str_starts_with($f['name'], 'restopro-'))
            ->sortByDesc('name')->slice($keep)
            ->each(fn ($f) => Http::withToken($this->token())->timeout(30)->delete(self::API.'/files/'.$f['id']));
    }

    public function test(): string
    {
        $r = Http::withToken($this->token())->timeout(30)->get(self::API.'/about', ['fields' => 'user(displayName,emailAddress)']);
        if (! $r->ok()) {
            throw new RuntimeException('Google Drive check failed ('.$r->status().')');
        }

        return 'Connected as '.($r->json('user.emailAddress') ?? $r->json('user.displayName') ?? 'Google user');
    }
}
