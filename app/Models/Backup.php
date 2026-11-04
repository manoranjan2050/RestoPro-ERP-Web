<?php

namespace App\Models;

use App\Services\Backup\BackupService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Backup extends Model
{
    protected $fillable = ['filename', 'size', 'sha256', 'trigger', 'counts', 'destinations', 'created_by'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'destinations' => 'array', 'size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (self $b) => Storage::disk(BackupService::DISK)->delete(BackupService::DIR.'/'.$b->filename));
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function path(): string
    {
        return Storage::disk(BackupService::DISK)->path(BackupService::DIR.'/'.$this->filename);
    }

    public function exists(): bool
    {
        return Storage::disk(BackupService::DISK)->exists(BackupService::DIR.'/'.$this->filename);
    }

    public function sizeLabel(): string
    {
        $b = $this->size;

        return $b >= 1048576 ? round($b / 1048576, 1).' MB' : max(1, round($b / 1024)).' KB';
    }

    public function totalRows(): int
    {
        return array_sum($this->counts ?? []);
    }
}
