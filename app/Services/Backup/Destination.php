<?php

namespace App\Services\Backup;

interface Destination
{
    public function label(): string;

    /** Has everything needed to upload (credentials present). */
    public function configured(): bool;

    /** Upload a local file; throw on failure. */
    public function upload(string $localPath, string $filename): void;

    /** Keep only the newest $keep backups at the destination. */
    public function prune(int $keep): void;

    /** Check credentials; return a short human description (e.g. account name). Throw on failure. */
    public function test(): string;
}
