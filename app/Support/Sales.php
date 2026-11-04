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

    /**
     * Type of business → a preset for the sales features, cake weights and suggested menu categories.
     * Choosing a type only fills these in; every switch can still be changed afterwards.
     */
    public const SHOP_TYPES = [
        'cake_shop' => ['Cake shop / Bakery', '🎂', 'Counter sales, custom cake orders with advance, home delivery, stock of cakes & pastries.',
            ['tables' => false, 'delivery' => true, 'advance' => true, 'stock' => true], 'both',
            ['Cakes', 'Pastries', 'Cupcakes & Muffins', 'Cookies', 'Breads', 'Snacks', 'Beverages']],
        'restaurant' => ['Restaurant', '🍽️', 'Dine-in tables with KOT, takeaway and delivery.',
            ['tables' => true, 'delivery' => true, 'advance' => false, 'stock' => false], 'kg',
            ['Starters', 'Main course', 'Breads', 'Rice & Biryani', 'Chinese', 'Desserts', 'Beverages']],
        'hotel' => ['Hotel', '🏨', 'Restaurant tables + room service delivery, advance bookings for functions.',
            ['tables' => true, 'delivery' => true, 'advance' => true, 'stock' => false], 'kg',
            ['Breakfast', 'Starters', 'Main course', 'Breads', 'Rice & Biryani', 'Desserts', 'Beverages', 'Room service']],
        'cafe' => ['Café', '☕', 'Counter + a few tables, quick bills, stock of baked items.',
            ['tables' => true, 'delivery' => true, 'advance' => false, 'stock' => true], 'kg',
            ['Coffee', 'Tea', 'Shakes & Smoothies', 'Sandwiches', 'Snacks', 'Desserts']],
        'sweet_shop' => ['Sweet shop', '🍬', 'Sweets sold by weight or piece, festival bulk orders with advance, stock.',
            ['tables' => false, 'delivery' => true, 'advance' => true, 'stock' => true], 'kg',
            ['Sweets', 'Bengali sweets', 'Dry fruit sweets', 'Namkeen', 'Snacks', 'Beverages']],
        'fast_food' => ['Fast food / Dhaba', '🍔', 'Quick counter and takeaway, a few tables, delivery.',
            ['tables' => true, 'delivery' => true, 'advance' => false, 'stock' => false], 'kg',
            ['Burgers', 'Pizzas', 'Rolls & Wraps', 'Momos', 'Chinese', 'Beverages']],
        'other' => ['Other', '🏪', 'Set every switch yourself.', null, null, []],
    ];

    public static function shopType(): string
    {
        $t = (string) Setting::get('shop_type', 'other');

        return array_key_exists($t, self::SHOP_TYPES) ? $t : 'other';
    }

    /** How cake weights are written in this shop: kg, lb (pound) or both. */
    public const WEIGHT_UNITS = ['kg' => 'Kg / gram', 'lb' => 'Pound', 'both' => 'Both'];

    /** Standard cake sizes for the quick "add sizes" buttons and the advance-order weight picker. */
    public const SIZES = [
        'kg' => ['500 g', '1 kg', '1.5 kg', '2 kg', '3 kg'],
        'lb' => ['0.5 lb', '1 lb', '1.5 lb', '2 lb', '3 lb'],
    ];

    public static function weightUnit(): string
    {
        $u = (string) Setting::get('weight_unit', 'kg');

        return array_key_exists($u, self::WEIGHT_UNITS) ? $u : 'kg';
    }

    /** @return array<string, list<string>> size sets to offer, e.g. ['kg' => [...]] or both */
    public static function sizeSets(): array
    {
        $u = self::weightUnit();

        return $u === 'both' ? self::SIZES : [$u => self::SIZES[$u]];
    }

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
