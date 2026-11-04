<?php

namespace App\Services;

use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Bulk add / update items from a CSV or Excel sheet (first row = headings, any order, case-insensitive):
 *
 *   name*  category  price  sizes                     code  barcode  unit  gst  hsn   type  description
 *   Black Forest  Cakes   …  "500 g=450; 1 kg=850"     BF    …        pcs   5    1905  egg   …
 *
 * `sizes` creates variants (name=price pairs separated by ; or |) and then `price` is ignored.
 * An existing item is matched by `code`, else by name (case-insensitive) and updated; otherwise it is created.
 * Unknown categories are created.
 */
class ItemImport
{
    public const COLUMNS = ['name', 'category', 'price', 'sizes', 'code', 'barcode', 'unit', 'gst', 'hsn', 'type', 'description'];

    private const ALIASES = [
        'item' => 'name', 'item name' => 'name', 'product' => 'name', 'rate' => 'price', 'mrp' => 'price',
        'variants' => 'sizes', 'size' => 'sizes', 'gst %' => 'gst', 'gst rate' => 'gst', 'tax' => 'gst',
        'veg' => 'type', 'food type' => 'type', 'sku' => 'code', 'short code' => 'code', 'group' => 'category',
    ];

    /** @return array{rows: int, create: int, update: int, errors: list<string>, sample: list<array>} */
    public static function preview(string $path, string $ext): array
    {
        $rows = self::rows($path, $ext);
        $errors = [];
        $create = $update = 0;
        foreach ($rows as $n => $r) {
            foreach (self::check($r) as $e) {
                $errors[] = 'Row '.($n + 2).": {$e}";
            }
            self::existing($r) ? $update++ : $create++;
        }

        return ['rows' => count($rows), 'create' => $create, 'update' => $update, 'errors' => array_slice($errors, 0, 15), 'sample' => array_slice($rows, 0, 5)];
    }

    /** @return array{created: int, updated: int} */
    public static function run(string $path, string $ext): array
    {
        $rows = self::rows($path, $ext);
        foreach ($rows as $n => $r) {
            if ($e = self::check($r)) {
                throw new RuntimeException('Row '.($n + 2).': '.$e[0]);
            }
        }
        $created = $updated = 0;
        DB::transaction(function () use ($rows, &$created, &$updated) {
            $cats = [];
            foreach ($rows as $r) {
                $catId = null;
                if ($r['category'] !== '') {
                    $key = mb_strtolower($r['category']);
                    $catId = $cats[$key] ??= (ItemCategory::whereRaw('LOWER(name) = ?', [$key])->value('id')
                        ?? ItemCategory::create(['name' => $r['category'], 'color' => ItemCategory::nextColor(), 'sort' => (int) ItemCategory::max('sort') + 1])->id);
                }
                $sizes = self::sizes($r['sizes']);
                $item = self::existing($r) ?? new Item(['unit' => 'pcs', 'gst_rate' => 5, 'is_active' => true]);
                $item->exists ? $updated++ : $created++;
                $item->fill(array_filter([
                    'name' => $r['name'], 'category_id' => $catId, 'code' => $r['code'] !== '' ? mb_strtoupper($r['code']) : null,
                    'barcode' => $r['barcode'] ?: null, 'unit' => $r['unit'] ?: null, 'hsn' => $r['hsn'] ?: null,
                    'food_type' => self::foodType($r['type']), 'description' => $r['description'] ?: null,
                    'gst_rate' => $r['gst'] !== '' ? (float) $r['gst'] : null,
                    'price' => $sizes ? min($sizes) : ($r['price'] !== '' ? (float) $r['price'] : null),
                ], fn ($v) => $v !== null));
                if (! $item->exists && ! $sizes && $r['price'] === '') {
                    $item->price = 0;
                }
                $item->save();
                $i = 0;
                foreach ($sizes as $name => $price) {
                    $item->variants()->updateOrCreate(['name' => $name], ['price' => $price, 'sort' => $i++, 'is_active' => true]);
                }
            }
        });

        return compact('created', 'updated');
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<array<string,string>> rows keyed by COLUMNS, blank rows dropped */
    private static function rows(string $path, string $ext): array
    {
        $ext = strtolower($ext);
        $reader = IOFactory::createReader(match ($ext) { 'xlsx' => 'Xlsx', 'xls' => 'Xls', default => 'Csv' });
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);
        if (count($sheet) < 2) {
            throw new RuntimeException('The file needs a heading row and at least one item.');
        }
        $head = array_map(function ($h) {
            $h = mb_strtolower(trim((string) $h));
            $h = trim(preg_replace('/\s*\(.*\)$/', '', $h)); // "price (₹)" → "price"

            return self::ALIASES[$h] ?? $h;
        }, array_shift($sheet));
        if (! in_array('name', $head, true)) {
            throw new RuntimeException('No "name" column found. Download the template to see the headings.');
        }
        $out = [];
        foreach ($sheet as $line) {
            $r = array_fill_keys(self::COLUMNS, '');
            foreach ($head as $i => $col) {
                if (in_array($col, self::COLUMNS, true)) {
                    $r[$col] = trim((string) ($line[$i] ?? ''));
                }
            }
            if ($r['name'] !== '') {
                $out[] = $r;
            }
        }
        if (count($out) > 2000) {
            throw new RuntimeException('Up to 2,000 items per file, please.');
        }

        return $out;
    }

    private static function check(array $r): array
    {
        $e = [];
        if (mb_strlen($r['name']) > 100) $e[] = 'name is longer than 100 characters';
        if ($r['price'] !== '' && (! is_numeric($r['price']) || $r['price'] < 0)) $e[] = "price “{$r['price']}” is not a number";
        if ($r['gst'] !== '' && ! in_array((float) $r['gst'], array_map('floatval', Item::GST_RATES), true)) $e[] = "GST “{$r['gst']}” must be 0, 5, 12, 18 or 28";
        if ($r['unit'] !== '' && ! array_key_exists($r['unit'], Item::UNITS)) $e[] = "unit “{$r['unit']}” must be one of ".implode(', ', array_keys(Item::UNITS));
        if ($r['hsn'] !== '' && ! preg_match('/^\d{4,8}$/', $r['hsn'])) $e[] = "HSN “{$r['hsn']}” must be 4–8 digits";
        if ($r['sizes'] !== '' && ! self::sizes($r['sizes'])) $e[] = 'sizes must look like "500 g=450; 1 kg=850"';

        return $e;
    }

    /** "500 g=450; 1 kg=850" → ['500 g' => 450.0, '1 kg' => 850.0] (empty array if invalid) */
    private static function sizes(string $s): array
    {
        if (trim($s) === '') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[;|]/', $s) as $part) {
            if (trim($part) === '') {
                continue;
            }
            if (! preg_match('/^\s*(.+?)\s*[=:]\s*([0-9]+(?:\.[0-9]+)?)\s*$/', $part, $m)) {
                return [];
            }
            $out[mb_substr($m[1], 0, 40)] = (float) $m[2];
        }

        return $out;
    }

    private static function foodType(string $t): ?string
    {
        $t = mb_strtolower(trim($t));

        return match (true) {
            $t === '' => null,
            str_contains($t, 'non') => 'nonveg',
            str_contains($t, 'egg') => 'egg',
            default => 'veg',
        };
    }

    private static function existing(array $r): ?Item
    {
        if ($r['code'] !== '' && ($i = Item::where('code', mb_strtoupper($r['code']))->first())) {
            return $i;
        }

        return Item::whereRaw('LOWER(name) = ?', [mb_strtolower($r['name'])])->first();
    }

    /** CSV template with two example rows. */
    public static function template(): string
    {
        $rows = [
            self::COLUMNS,
            ['Black Forest Cake', 'Cakes', '', '500 g=450; 1 kg=850; 2 kg=1650', 'BF', '', 'pcs', '5', '1905', 'egg', 'Chocolate sponge, cherries, cream'],
            ['Veg Puff', 'Snacks', '25', '', 'VP', '', 'pcs', '5', '1905', 'veg', ''],
        ];
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        rewind($fh);

        return stream_get_contents($fh);
    }
}
