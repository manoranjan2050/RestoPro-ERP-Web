<?php

namespace App\Services\Backup;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * GitHub private repository via the Contents API and a fine-grained token (Contents: read & write, one repo).
 * Refuses to upload to a public repository — backups contain password hashes and bill photos.
 * Settings: backup_github_repo (owner/name), backup_github_branch, backup_github_path, backup_github_token (enc).
 */
class GithubDestination implements Destination
{
    public function label(): string
    {
        return 'GitHub';
    }

    public function configured(): bool
    {
        return (bool) (Setting::get('backup_github_repo') && Setting::secret('backup_github_token'));
    }

    private function http()
    {
        return Http::withToken(Setting::secret('backup_github_token'))->timeout(120)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28']);
    }

    private function repo(): string
    {
        $repo = trim((string) Setting::get('backup_github_repo'));
        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new RuntimeException('GitHub repository must look like owner/name.');
        }

        return $repo;
    }

    private function branch(): string
    {
        return Setting::get('backup_github_branch') ?: 'main';
    }

    private function dir(): string
    {
        return trim(Setting::get('backup_github_path') ?: 'backups', '/');
    }

    private function assertPrivate(): array
    {
        $r = $this->http()->get('https://api.github.com/repos/'.$this->repo());
        if ($r->status() === 404) {
            throw new RuntimeException('Repository not found, or the token cannot see it.');
        }
        if (! $r->ok()) {
            throw new RuntimeException('GitHub check failed ('.$r->status().'): '.($r->json('message') ?? ''));
        }
        if (! $r->json('private')) {
            throw new RuntimeException('The repository is PUBLIC. Backups contain private data — use a private repository.');
        }

        return $r->json();
    }

    public function upload(string $localPath, string $filename): void
    {
        $this->assertPrivate();
        if (filesize($localPath) > 95 * 1024 * 1024) {
            throw new RuntimeException('Backup is larger than GitHub allows (100 MB). Use Dropbox or Google Drive.');
        }
        $r = $this->http()->put('https://api.github.com/repos/'.$this->repo().'/contents/'.$this->dir().'/'.$filename, [
            'message' => 'Backup '.$filename,
            'content' => base64_encode(file_get_contents($localPath)),
            'branch' => $this->branch(),
        ]);
        if (! $r->successful()) {
            throw new RuntimeException('GitHub upload failed ('.$r->status().'): '.($r->json('message') ?? ''));
        }
    }

    public function prune(int $keep): void
    {
        $r = $this->http()->get('https://api.github.com/repos/'.$this->repo().'/contents/'.$this->dir(), ['ref' => $this->branch()]);
        if (! $r->ok() || ! is_array($r->json())) {
            return;
        }
        collect($r->json())->filter(fn ($f) => ($f['type'] ?? '') === 'file' && str_starts_with($f['name'], 'restopro-'))
            ->sortByDesc('name')->slice($keep)
            ->each(fn ($f) => $this->http()->delete('https://api.github.com/repos/'.$this->repo().'/contents/'.$f['path'], [
                'message' => 'Remove old backup '.$f['name'], 'sha' => $f['sha'], 'branch' => $this->branch(),
            ]));
    }

    public function test(): string
    {
        $repo = $this->assertPrivate();

        return 'Private repo '.$repo['full_name'].' · branch '.$this->branch();
    }
}
