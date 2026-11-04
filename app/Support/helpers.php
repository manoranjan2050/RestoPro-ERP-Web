<?php

use App\Models\Setting;

if (! function_exists('inr_group')) {
    /** Indian digit grouping: 1234567.5 → "12,34,567.50" (decimals optional). */
    function inr_group(float|int|string|null $value, int $decimals = 0): string
    {
        $value = (float) $value;
        $neg = $value < 0;
        $fixed = number_format(abs($value), $decimals, '.', '');
        [$int, $frac] = array_pad(explode('.', $fixed), 2, null);

        if (strlen($int) > 3) {
            $last3 = substr($int, -3);
            $rest = substr($int, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int = $rest.','.$last3;
        }

        return ($neg ? '-' : '').$int.($frac !== null ? '.'.$frac : '');
    }
}

if (! function_exists('report_cell')) {
    /** Format a report value by column type (text · money · int · num · pct · date). */
    function report_cell(mixed $v, string $type): string
    {
        if ($v === '' || $v === null) {
            return '';
        }

        return match ($type) {
            'money' => money($v),
            'int' => (string) (int) $v,
            'num' => rtrim(rtrim(number_format((float) $v, 2), '0'), '.'),
            'pct' => rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%',
            // Totals rows put labels like "Total" in date columns — pass those through untouched.
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v) ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : (string) $v,
            default => (string) $v,
        };
    }
}

if (! function_exists('money')) {
    /** Currency-formatted amount; shows paise only when present. */
    function money(float|int|string|null $value, ?bool $decimals = null): string
    {
        $value = (float) $value;
        $decimals ??= fmod(abs($value), 1) > 0.004;

        return ($value < 0 ? '-' : '').Setting::get('currency_symbol', '₹').inr_group(abs($value), $decimals ? 2 : 0);
    }
}
