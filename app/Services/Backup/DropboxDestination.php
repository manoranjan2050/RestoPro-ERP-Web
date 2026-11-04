<?php

namespace App\Services\Backup;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Dropbox via an "App folder" app. Connect uses OAuth PKCE, so only the App key is needed (no secret).
 * Settings: backup_dropbox_app_key, backup_dropbox_refresh (encrypted).
 */
class DropboxDestination implements Destination
{
    public function label(): string
    {
        return 'Dropbox';
    }

    public function configured(): bool
    {
        return (bool) (Setting::get('backup_dropbox_app_key') && Setting::secret('backup_dropbox_refresh'));
    }

    public static function authorizeUrl(string $redirect, string $state, string $challenge): string
    {
        return 'https://www.dropbox.com/oauth2/authorize?'.http_build_query([
            'client_id' => Setting::get('backup_dropbox_app_key'),
            'response_type' => 'code',
            'token_access_type' => 'offline',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'redirect_uri' => $redirect,
            'state' => $state,
        ]);
    }

    /** Exchange the OAuth code for a long-lived refresh token and store it. */
    public static function finishConnect(string $code, string $verifier, string $redirect): void
    {
        $r = Http::asForm()->timeout(30)->post('https://api.dropboxapi.com/oauth2/token', [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => Setting::get('backup_dropbox_app_key'),
            'code_verifier' => $verifier,
            'redirect_uri' => $redirect,
        ]);
        if (! $r->ok() || ! $r->json('refresh_token')) {
            throw new RuntimeException('Dropbox did not return a refresh token: '.($r->json('error_description') ?? $r->status()));
        }
        Setting::putSecret('backup_dropbox_refresh', $r->json('refresh_token'));
        Cache::forget('dropbox.token');
    }

    private function token(): string
    {
        return Cache::remember('dropbox.token', 3 * 3600, function () {
            $r = Http::asForm()->timeout(30)->post('https://api.dropboxapi.com/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => Setting::secret('backup_dropbox_refresh'),
                'client_id' => Setting::get('backup_dropbox_app_key'),
            ]);
            if (! $r->ok() || ! $r->json('access_token')) {
                throw new RuntimeException('Dropbox login expired — connect Dropbox again. ('.($r->json('error_description') ?? $r->status()).')');
            }

            return $r->json('access_token');
        });
    }

    public function upload(string $localPath, string $filename): void
    {
        $r = Http::withToken($this->token())->timeout(300)
            ->withHeaders(['Dropbox-API-Arg' => json_encode(['path' => '/'.$filename, 'mode' => 'add', 'autorename' => true])])
            ->withBody(file_get_contents($localPath), 'application/octet-stream')
            ->post('https://content.dropboxapi.com/2/files/upload');
        if (! $r->ok()) {
            throw new RuntimeException('Dropbox upload failed ('.$r->status().'): '.substr($r->body(), 0, 150));
        }
    }

    public function prune(int $keep): void
    {
        $r = Http::withToken($this->token())->timeout(30)->post('https://api.dropboxapi.com/2/files/list_folder', ['path' => '']);
        if (! $r->ok()) {
            return;
        }
        collect($r->json('entries', []))
            ->filter(fn ($e) => ($e['.tag'] ?? '') === 'file' && str_starts_with($e['name'], 'restopro-') && str_ends_with($e['name'], '.zip'))
            ->sortByDesc('name')->slice($keep)
            ->each(fn ($e) => Http::withToken($this->token())->timeout(30)->post('https://api.dropboxapi.com/2/files/delete_v2', ['path' => $e['path_lower']]));
    }

    public function test(): string
    {
        $r = Http::withToken($this->token())->timeout(30)->withBody('null', 'application/json')
            ->post('https://api.dropboxapi.com/2/users/get_current_account');
        if (! $r->ok()) {
            throw new RuntimeException('Dropbox check failed ('.$r->status().')');
        }

        return 'Connected as '.($r->json('name.display_name') ?? $r->json('email') ?? 'Dropbox user');
    }
}
