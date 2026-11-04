<?php

namespace App\Services;

use App\Models\CashCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Revenue figures for a date range, grouped by day / week / month.
 * Grouping is done in PHP (≤ a few hundred rows per range) so it is identical on SQLite and MySQL.
 */
class RevenueReport
{
    public const PRESETS = [
        'today' => 'Today',
        'week' => 'This week',
        'month' => 'This month',
        'last_month' => 'Last month',
        '30d' => 'Last 30 days',
        'fy' => 'This financial year',
        'year' => 'This year',
        'custom' => 'Custom',
    ];

    public function __construct(public Carbon $from, public Carbon $to, public string $group = 'day') {}

    /** @return array{0: Carbon, 1: Carbon} */
    public static function presetRange(string $preset, int $fyStartMonth = 4): array
    {
        $t = today();

        return match ($preset) {
            'today' => [$t->copy(), $t->copy()],
            'week' => [$t->copy()->startOfWeek(), $t->copy()],
            'last_month' => [$t->copy()->subMonthNoOverflow()->startOfMonth(), $t->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            '30d' => [$t->copy()->subDays(29), $t->copy()],
            'fy' => [self::fyStart($t, $fyStartMonth), $t->copy()],
            'year' => [$t->copy()->startOfYear(), $t->copy()],
            default => [$t->copy()->startOfMonth(), $t->copy()],
        };
    }

    public static function fyStart(Carbon $date, int $startMonth = 4): Carbon
    {
        $year = $date->month >= $startMonth ? $date->year : $date->year - 1;

        return Carbon::create($year, $startMonth, 1)->startOfDay();
    }

    public function entries(): Collection
    {
        return CashCollection::between($this->from, $this->to)->orderBy('date')->get();
    }

    /** Rows grouped by period, newest first, each with cash/upi/online/total/days. */
    public function rows(): Collection
    {
        return $this->entries()
            ->groupBy(fn ($e) => $this->periodKey($e->date))
            ->map(function (Collection $items, string $key) {
                $first = $items->first()->date;

                return [
                    'key' => $key,
                    'label' => $this->periodLabel($first),
                    'sub' => $this->group === 'day' ? $first->format('l') : $items->count().' day(s)',
                    'cash' => (float) $items->sum('cash'),
                    'upi' => (float) $items->sum('upi'),
                    'online' => (float) $items->sum('online'),
                    'total' => (float) $items->sum('total'),
                    'days' => $items->count(),
                ];
            })
            ->sortByDesc('key')
            ->values();
    }

    public function totals(): array
    {
        return CashCollection::totals(CashCollection::between($this->from, $this->to));
    }

    /** Daily series (oldest → newest) for the trend chart; empty days are 0. */
    public function dailySeries(): array
    {
        $byDate = $this->entries()->keyBy(fn ($e) => $e->date->toDateString());
        $labels = $values = [];
        $days = (int) $this->from->diffInDays($this->to) + 1;

        // Long ranges are charted per month to stay readable.
        if ($days > 62) {
            $months = [];
            foreach ($byDate as $d => $e) {
                $k = substr($d, 0, 7);
                $months[$k] = ($months[$k] ?? 0) + (float) $e->total;
            }
            for ($m = $this->from->copy()->startOfMonth(); $m->lte($this->to); $m->addMonthNoOverflow()) {
                $labels[] = $m->format('M y');
                $values[] = round($months[$m->format('Y-m')] ?? 0, 2);
            }

            return compact('labels', 'values');
        }

        for ($d = $this->from->copy(); $d->lte($this->to); $d->addDay()) {
            $labels[] = $d->format('d M');
            $values[] = round((float) ($byDate[$d->toDateString()]->total ?? 0), 2);
        }

        return compact('labels', 'values');
    }

    private function periodKey(Carbon $d): string
    {
        return match ($this->group) {
            'week' => $d->copy()->startOfWeek()->toDateString(),
            'month' => $d->format('Y-m'),
            default => $d->toDateString(),
        };
    }

    private function periodLabel(Carbon $d): string
    {
        return match ($this->group) {
            'week' => $d->copy()->startOfWeek()->format('d M').' – '.$d->copy()->endOfWeek()->format('d M Y'),
            'month' => $d->format('F Y'),
            default => $d->format('d M Y'),
        };
    }
}
