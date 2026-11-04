<?php

namespace App\Services;

/**
 * Bill maths in one place, used by the POS screen (live totals) and when saving (stored totals).
 *
 * Input lines: [qty, rate, gst_rate]. A bill discount (amount or %) is spread over lines in proportion to their amount.
 *   GST off:       line total = amount - discount
 *   GST inclusive: line total = amount - discount; taxable = total / (1 + r); tax = total - taxable
 *   GST exclusive: taxable = amount - discount; tax = taxable * r; total = taxable + tax
 * Tax is split equally into CGST + SGST (sale within the state). The bill total is rounded to the rupee when asked.
 */
class BillCalculator
{
    public static function calc(array $lines, float $discountValue = 0, string $discountType = 'amt', bool $gst = false,
        bool $inclusive = true, bool $roundOff = true, float $extra = 0): array
    {
        $amounts = [];
        foreach ($lines as $i => $l) {
            $amounts[$i] = round(max(0, (float) $l['qty']) * max(0, (float) $l['rate']), 2);
        }
        $subtotal = round(array_sum($amounts), 2);

        $discount = $discountType === 'pct'
            ? round($subtotal * min(max($discountValue, 0), 100) / 100, 2)
            : round(min(max($discountValue, 0), $subtotal), 2);

        // Spread the discount; the last line takes the paise left over so the shares add up exactly.
        $shares = [];
        $left = $discount;
        $keys = array_keys($amounts);
        foreach ($keys as $n => $i) {
            $shares[$i] = $n === count($keys) - 1 ? $left : ($subtotal > 0 ? round($discount * $amounts[$i] / $subtotal, 2) : 0.0);
            $left = round($left - $shares[$i], 2);
        }

        $out = [];
        $byRate = [];
        $taxable = $tax = $total = 0.0;
        foreach ($lines as $i => $l) {
            $net = round($amounts[$i] - $shares[$i], 2);
            $r = $gst ? (float) ($l['gst_rate'] ?? 0) / 100 : 0;
            if ($r > 0 && $inclusive) {
                $lt = $net;
                $lx = round($net / (1 + $r), 2);
                $lg = round($lt - $lx, 2);
            } elseif ($r > 0) {
                $lx = $net;
                $lg = round($net * $r, 2);
                $lt = round($lx + $lg, 2);
            } else {
                [$lx, $lg, $lt] = [$net, 0.0, $net];
            }
            $out[$i] = $l + ['amount' => $amounts[$i], 'discount' => $shares[$i], 'taxable' => $lx, 'tax' => $lg, 'total' => $lt];
            $taxable += $lx;
            $tax += $lg;
            $total += $lt;
            if ($gst) {
                $k = (string) (float) ($l['gst_rate'] ?? 0);
                $byRate[$k]['taxable'] = round(($byRate[$k]['taxable'] ?? 0) + $lx, 2);
                $byRate[$k]['tax'] = round(($byRate[$k]['tax'] ?? 0) + $lg, 2);
            }
        }
        ksort($byRate, SORT_NUMERIC);

        $tax = round($tax, 2);
        $cgst = round($tax / 2, 2);
        $sgst = round($tax - $cgst, 2);
        $gross = round($total + max(0, $extra), 2); // + delivery charge (no GST on it)
        $final = $roundOff ? round($gross) : $gross;

        return [
            'lines' => $out, 'subtotal' => $subtotal, 'discount' => $discount, 'taxable' => round($taxable, 2), 'tax' => $tax,
            'cgst' => $cgst, 'sgst' => $sgst, 'round_off' => round($final - $gross, 2), 'total' => round($final, 2), 'by_rate' => $byRate,
        ];
    }
}
