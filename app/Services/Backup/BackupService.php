<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Backups are a single ZIP that works on any database (SQLite locally, MySQL on Hostinger):
 *
 *   manifest.json   app, format, created_at, last migration, row counts, sha256 of data.json
 *   data.json       every business table as plain rows
 *   files/public/…  avatars, staff photos, logos      files/private/bills/…  bill photos
 *
 * Restore replaces all business data and files with the backup's, inside one transaction, after taking an
 * automatic "pre-restore" safety backup. Sessions, cache and the backups list itself are never touched.
 */
class BackupService
{
    public const DISK = 'local';
    public const DIR = 'backups';
    public const FORMAT = 1;

    /** Insert order (parents first). Restore deletes in reverse. */
    public const TABLES = [
        'users', 'settings', 'expense_categories', 'vendors', 'staff',
        'cash_collections', 'expenses', 'vendor_payments', 'vendor_payment_allocations', 'attendances', 'staff_payments',
        // v2 sales
        'item_categories', 'items', 'item_variants', 'customers', 'dining_tables', 'orders', 'order_items', 'order_payments',
    ];

    /** Settings that must never travel in a backup (they belong to this server). */
    private const LOCAL_SETTINGS_PREFIX = 'backup_';

    // ------------------------------------------------------------------ create

    public static function create(string $trigger = 'manual', ?int $userId = null, bool $upload = true): Backup
    {
        @set_time_limit(300);
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(self::DIR);

        $data = [];
        $counts = [];
        foreach (self::TABLES as $t) {
            $q = DB::table($t);
            if ($t === 'settings') {
                $q->where('key', 'not like', self::LOCAL_SETTINGS_PREFIX.'%');
            }
            $rows = $q->orderBy($t === 'settings' ? 'key' : 'id')->get()->map(fn ($r) => (array) $r)->all();
            $data[$t] = $rows;
            $counts[$t] = count($rows);
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $manifest = [
            'app' => 'restopro-erp',
            'format' => self::FORMAT,
            'business' => Setting::get('business_name'),
            'created_at' => now()->toIso8601String(),
            'trigger' => $trigger,
            'migration' => DB::table('migrations')->orderByDesc('id')->value('migration'),
            'counts' => $counts,
            'data_sha256' => hash('sha256', $json),
        ];

        $filename = 'restopro-'.now()->format('Y-m-d_His').'-'.$trigger.'-'.Str::lower(Str::random(4)).'.zip';
        $path = $disk->path(self::DIR.'/'.$filename);

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create backup file.');
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('data.json', $json);
        foreach (self::fileSources() as $prefix => [$fsDisk, $dirs]) {
            foreach ($dirs as $dir) {
                foreach (Storage::disk($fsDisk)->allFiles($dir) as $file) {
                    $zip->addFile(Storage::disk($fsDisk)->path($file), "files/{$prefix}/{$file}");
                }
            }
        }
        $zip->close();

        $backup = Backup::create([
            'filename' => $filename,
            'size' => filesize($path),
            'sha256' => hash_file('sha256', $path),
            'trigger' => $trigger,
            'counts' => $counts,
            'destinations' => [],
            'created_by' => $userId,
        ]);

        if ($upload) {
            self::upload($backup);
        }
        self::prune();

        return $backup;
    }

    /** Send a backup to every connected cloud destination; failures are recorded, never thrown. */
    public static function upload(Backup $backup): Backup
    {
        $results = $backup->destinations ?? [];
        foreach (Destinations::enabled() as $key => $dest) {
            try {
                $dest->upload($backup->path(), $backup->filename);
                $dest->prune((int) Setting::get('backup_keep_cloud', '30'));
                $results[$key] = ['ok' => true, 'message' => 'Uploaded', 'at' => now()->toIso8601String()];
            } catch (\Throwable $e) {
                report($e);
                $results[$key] = ['ok' => false, 'message' => Str::limit($e->getMessage(), 180), 'at' => now()->toIso8601String()];
            }
        }
        $backup->update(['destinations' => $results]);
        Setting::put('backup_last_at', now()->toIso8601String());

        return $backup;
    }

    /** Keep the newest N local backups (pre-restore safety copies are kept for 30 days on top). */
    public static function prune(): int
    {
        $keep = max(1, (int) Setting::get('backup_keep', '14'));
        $old = Backup::where('trigger', '!=', 'pre-restore')->orderByDesc('created_at')->orderByDesc('id')->skip($keep)->take(1000)->get()
            ->merge(Backup::where('trigger', 'pre-restore')->where('created_at', '<', now()->subDays(30))->get());
        $old->each->delete();

        return $old->count();
    }

    // ----------------------------------------------------------------- inspect

    /** Validate a ZIP and return its manifest; throws a friendly RuntimeException if it isn't a usable backup. */
    public static function inspect(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This is not a valid ZIP file.');
        }
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            $json = $zip->getFromName('data.json');
            if (! is_array($manifest) || ($manifest['app'] ?? null) !== 'restopro-erp' || $json === false) {
                throw new RuntimeException('This ZIP is not a RestoPro backup.');
            }
            if (($manifest['format'] ?? 0) > self::FORMAT) {
                throw new RuntimeException('This backup was made by a newer version of the app. Update the app first.');
            }
            if (! hash_equals((string) ($manifest['data_sha256'] ?? ''), hash('sha256', $json))) {
                throw new RuntimeException('The backup is damaged (checksum does not match).');
            }
            $current = DB::table('migrations')->orderByDesc('id')->value('migration');
            if (($manifest['migration'] ?? '') > (string) $current) {
                throw new RuntimeException('This backup has newer database changes than this app. Update the app first.');
            }
            $manifest['files'] = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_starts_with((string) $zip->getNameIndex($i), 'files/') && ! str_ends_with((string) $zip->getNameIndex($i), '/')) {
                    $manifest['files']++;
                }
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    // ----------------------------------------------------------------- restore

    /**
     * Replace all business data + files with the backup's. Returns the safety backup taken first.
     */
    public static function restore(string $path, ?int $userId = null): Backup
    {
        @set_time_limit(600);
        self::inspect($path);

        $zip = new ZipArchive;
        $zip->open($path);
        $data = json_decode((string) $zip->getFromName('data.json'), true, 512, JSON_THROW_ON_ERROR);

        // 1. Safety net: current state, local only (fast, no network).
        $safety = self::create('pre-restore', $userId, upload: false);

        // 2. Data, all-or-nothing.
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($data) {
                foreach (array_reverse(self::TABLES) as $t) {
                    $q = DB::table($t);
                    if ($t === 'settings') {
                        $q->where('key', 'not like', self::LOCAL_SETTINGS_PREFIX.'%');
                    }
                    $q->delete();
                }
                foreach (self::TABLES as $t) {
                    $columns = array_flip(Schema::getColumnListing($t));
                    $rows = array_map(fn ($r) => array_intersect_key($r, $columns), $data[$t] ?? []);
                    if ($t === 'settings') {
                        $rows = array_values(array_filter($rows, fn ($r) => ! str_starts_with((string) $r['key'], self::LOCAL_SETTINGS_PREFIX)));
                    }
                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table($t)->insert($chunk);
                    }
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // 3. Files: replace the folders that backups own.
        foreach (self::fileSources() as $prefix => [$fsDisk, $dirs]) {
            foreach ($dirs as $dir) {
                Storage::disk($fsDisk)->deleteDirectory($dir);
            }
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! preg_match('#^files/(public|private)/((?:avatars|staff|logos|items|bills)/[A-Za-z0-9._/-]+)$#', $name, $m) || str_contains($name, '..')) {
                continue; // ignore anything unexpected (no path traversal)
            }
            Storage::disk($m[1] === 'public' ? 'public' : 'local')->put($m[2], $zip->getFromIndex($i));
        }
        $zip->close();

        Cache::forget('settings.all');

        return $safety;
    }

    /** prefix => [disk, folders] that are included in backups. */
    private static function fileSources(): array
    {
        return [
            'public' => ['public', ['avatars', 'staff', 'logos', 'items']],
            'private' => ['local', ['bills']],
        ];
    }
}
