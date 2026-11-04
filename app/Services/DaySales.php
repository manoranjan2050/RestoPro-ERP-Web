<?php

namespace App\Services;

use App\Models\CashCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeps each day's Cash Collection row = hand-entered sales + POS bill payments.
 *
 *   cash / upi / online      = the DAY TOTALS (what every v1 report, ledger and chart reads)
 *   pos_cash / pos_upi / …   = the part that came from bills
 *   hand-entered part        = total − pos (the only part a person edits on the Cash Collection page)
 *
 * Phase 15's POS calls setPos() with the day's bill-payment sums whenever a payment changes.
 */
class DaySales
{
    public const MODES = ['cash', 'upi', 'online'];

    /** Replace the POS share of a day; the hand-entered share is kept. Row is created/removed as needed. */
    public static function setPos(string|Carbon $date, array $pos): ?CashCollection
    {
        $date = Carbon::parse($date)->toDateString();

        return DB::transaction(function () use ($date, $pos) {
            $row = CashCollection::whereDate('date', $date)->lockForUpdate()->first();
            $manual = $row ? self::manual($row) : array_fill_keys(self::MODES, 0.0);
            $posTotal = 0;
            foreach (self::MODES as $m) {
                $posTotal += $p = round((float) ($pos[$m] ?? 0), 2);
                $pos[$m] = $p;
            }

            if (! $row) {
                if ($posTotal == 0) {
                    return null;
                }
                $row = new CashCollection(['date' => $date]);
            }
            foreach (self::MODES as $m) {
                $row->{'pos_'.$m} = $pos[$m];
                $row->$m = round($manual[$m] + $pos[$m], 2);
            }
            // A day that only existed because of bills disappears when its bills are all refunded/cancelled.
            if ($posTotal == 0 && array_sum($manual) == 0 && ! $row->note) {
                $row->exists && $row->delete();

                return null;
            }
            $row->save();

            return $row;
        });
    }

    /** @return array{cash: float, upi: float, online: float} hand-entered part of a day */
    public static function manual(CashCollection $c): array
    {
        $out = [];
        foreach (self::MODES as $m) {
            $out[$m] = round(max(0, (float) $c->$m - (float) $c->{'pos_'.$m}), 2);
        }

        return $out;
    }

    /** @return array{cash: float, upi: float, online: float} */
    public static function pos(CashCollection $c): array
    {
        return array_combine(self::MODES, array_map(fn ($m) => (float) $c->{'pos_'.$m}, self::MODES));
    }

    public static function hasPos(CashCollection $c): bool
    {
        return array_sum(self::pos($c)) > 0;
    }

    /** Write a new hand-entered share on top of the POS share. */
    public static function fillManual(CashCollection $c, array $manual): CashCollection
    {
        foreach (self::MODES as $m) {
            $c->$m = round((float) ($manual[$m] ?? 0) + (float) $c->{'pos_'.$m}, 2);
        }

        return $c;
    }

    /** "Delete" from the Cash Collection page: removes the row, or only the hand-entered share when bills exist. */
    public static function deleteManual(CashCollection $c): void
    {
        if (! self::hasPos($c)) {
            $c->delete();

            return;
        }
        self::fillManual($c, [])->forceFill(['note' => null])->save();
    }
}
