<?php

namespace App\Support;

use App\Models\Setting;

/**
 * v2 switches. `simple` = v1 exactly (no sales screens). `advanced` = POS + the features toggled on below.
 * Switching hides/shows screens only — no data is ever removed.
 */
class Sales
{
    public const FEATURES = [
        'tables' => ['Dine-in tables + KOT', 'Open bills per table, kitchen order tickets, move/merge tables.'],
        'delivery' => ['Parcel / home delivery', 'Takeaway and delivery orders with address, charge and status board.'],
        'advance' => ['Advance / custom orders', 'Book cakes for a date: flavour, weight, message, advance and balance.'],
        'stock' => ['Stock', 'Track how many of an item are left; goes down on every sale.'],
    ];

    public static function advanced(): bool
    {
        return Setting::get('app_mode', 'simple') === 'advanced';
    }

    /** A v2 feature is on only in Advanced mode and when its own switch is on. */
    public static function on(string $feature): bool
    {
        return self::advanced() && Setting::get('sales_'.$feature, '1') === '1';
    }

    public static function gst(): bool
    {
        return self::advanced() && Setting::get('gst_enabled', '0') === '1';
    }

    /** Prices typed in the item list already include GST (the usual way for cake/sweet shops). */
    public static function gstInclusive(): bool
    {
        return Setting::get('gst_inclusive', '1') === '1';
    }
}
