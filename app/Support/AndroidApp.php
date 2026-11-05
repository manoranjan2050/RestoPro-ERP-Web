<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The Android app APK hosted on this server (until it is on the Play Store). Kept on the private disk and served only
 * to signed-in users; details live in settings (app_apk_*). Not part of backups — it can always be uploaded again.
 */
class AndroidApp
{
    public const PATH = 'app/RestoPro-ERP.apk';
    public const DISK = 'local';

    /** @return array{version: string, size: int, uploaded_at: ?string, sha256: ?string}|null */
    public static function info(): ?array
    {
        if (! Storage::disk(self::DISK)->exists(self::PATH)) {
            return null;
        }

        return [
            'version' => (string) Setting::get('app_apk_version', ''),
            'size' => (int) Storage::disk(self::DISK)->size(self::PATH),
            'uploaded_at' => Setting::get('app_apk_uploaded_at'),
            'sha256' => Setting::get('app_apk_sha256'),
        ];
    }

    public static function store(UploadedFile|string $file, string $version): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        Storage::disk(self::DISK)->put(self::PATH, fopen($path, 'r'));
        Setting::put('app_apk_version', trim($version));
        Setting::put('app_apk_uploaded_at', now()->toIso8601String());
        Setting::put('app_apk_sha256', hash_file('sha256', Storage::disk(self::DISK)->path(self::PATH)));

        return self::info();
    }

    public static function remove(): void
    {
        Storage::disk(self::DISK)->delete(self::PATH);
        foreach (['version', 'uploaded_at', 'sha256'] as $k) {
            Setting::put('app_apk_'.$k, null);
        }
    }

    public static function fileName(): string
    {
        $v = preg_replace('/[^0-9A-Za-z.]/', '', (string) Setting::get('app_apk_version', ''));

        return 'OpenRestoPro-ERP'.($v !== '' ? '-'.$v : '').'.apk';
    }

    public static function sizeLabel(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, round($bytes / 1024)).' KB';
    }
}
