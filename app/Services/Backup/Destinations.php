<?php

namespace App\Services\Backup;

use App\Models\Setting;

class Destinations
{
    /** @return array<string, Destination> */
    public static function all(): array
    {
        return [
            'dropbox' => new DropboxDestination,
            'gdrive' => new GoogleDriveDestination,
            'github' => new GithubDestination,
        ];
    }

    /** Configured destinations that are switched on. */
    public static function enabled(): array
    {
        return array_filter(self::all(), fn (Destination $d, string $k) => $d->configured() && Setting::get("backup_{$k}_enabled", '1') === '1',
            ARRAY_FILTER_USE_BOTH);
    }

    public static function get(string $key): Destination
    {
        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown destination {$key}");
    }
}
