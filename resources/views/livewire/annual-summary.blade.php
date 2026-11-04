@php
    $m = collect($a['months']);
    // Future months and months with no activity at all (e.g. before the app was used) are gaps, not zeros.
    $val = fn ($k) => $m->map(fn ($r) => $r['future'] || ($r['sales'] == 0 && $r['expenses'] == 0 && $r['staff'] == 0) ? null : $r[$k])->all();
    $labels = $m->pluck('label')->all();
    $t = $a['totals'];
    $c = $a['compare'];
    $combo = ['labels' => $labels, 'sales' => $val('sales'), 'expenses' => $val('expenses'), 'staff' => $val('staff'), 'net' => $val('net')];
    $compare = ['labels' => $labels, 'now' => $val('sales'), 'prev' => $a['prev_months'], 'nowLabel' => $a['label'], 'prevLabel' => $c['prev_label']];
    $growth = ['labels' => $labels, 'values' => $a['mom']];
    $cumulative = ['labels' => $labels, 'values' => $a['cumulative'], 'name' => 'Net profit so far'];
    $cats = ['labels' => array_column($a['categories'], 'name'), 'values' => array_map(fn ($x) => round($x, 2), array_column($a['categories'], 'amount')), 'colors' => array_column($a['categories'], 'color')];
    $modes = ['labels' => ['Cash', 'UPI', 'Online'], 'values' => [round($t['cash'], 2), round($t['upi'], 2), round($t['online'], 2)]];
    $week = ['labels' => array_column($a['weekday'], 'label'), 'values' => array_column($a['weekday'], 'avg')];
    $key = md5(json_encode([$a['fy'], $t, $a['mom']]));
    $badge = fn (?float $g, bool $goodWhenUp = true) => $g === null ? '' :
        '<span class="ml-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold '.((($g >= 0) === $goodWhenUp) ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400' : 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400').'">'.($g >= 0 ? '▲ ' : '▼ ').abs($g).'%</span>';
@endphp
<div class="space-y-6">
    {{-- Header --}}
    <div class="relative overflow-clip rounded-3xl bg-gradient-to-br from-violet-600 via-rose-500 to-brand-500 p-6 text-white shadow-xl shadow-violet-500/20 sm:p-8">
        <div class="pointer-events-none absolute -right-10 -top-10 size-48 rounded-full bg-white/10"></div>
        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-medium text-white/80">Annual summary · {{ $a['range'] }}</p>
                <h2 class="mt-1 text-3xl font-extrabold">{{ money($t['net']) }} <span class="text-lg font-semibold text-white/80">net profit</span></h2>
                <p class="mt-1 text-sm text-white/85">{{ money($t['sales']) }} sales · {{ $t['margin'] }}% margin · {{ $t['days'] }} trading days</p>
            </div>
            <select wire:model.live="fy" class="w-auto rounded-xl border-0 bg-white/15 py-2 pl-3 pr-9 text-sm font-semibold text-white backdrop-blur focus:ring-2 focus:ring-white/60 [&>option]:text-slate-900">
                @foreach ($years as $y => $l) <option value="{{ $y }}">{{ $l }}</option> @endforeach
            </select>
        </div>
    </div>

    {{-- KPIs --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <div class="card p-4 sm:p-5">
            <p class="text-xs font-medium text-slate-500 sm:text-sm">Sales {!! $badge($c['sales_growth']) !!}</p>
            <p class="mt-2 text-lg font-extrabold tabular-nums sm:text-2xl">{{ money($t['sales']) }}</p>
            <p class="mt-1.5 text-xs text-slate-500">Avg {{ money($t['avg_day'], false) }} / day</p>
        </div>
        <div class="card p-4 sm:p-5">
            <p class="text-xs font-medium text-slate-500 sm:text-sm">Expenses {!! $badge($c['exp_growth'], false) !!}</p>
            <p class="mt-2 text-lg font-extrabold tabular-nums sm:text-2xl">{{ money($t['expenses']) }}</p>
            <p class="mt-1.5 text-xs text-slate-500">{{ $t['sales'] > 0 ? round($t['expenses'] / $t['sales'] * 100) : 0 }}% of sales</p>
        </div>
        <div class="card p-4 sm:p-5">
            <p class="text-xs font-medium text-slate-500 sm:text-sm">Staff cost</p>
            <p class="mt-2 text-lg font-extrabold tabular-nums sm:text-2xl">{{ money($t['staff']) }}</p>
            <p class="mt-1.5 text-xs text-slate-500">{{ $t['sales'] > 0 ? round($t['staff'] / $t['sales'] * 100) : 0 }}% of sales</p>
        </div>
        <div class="card p-4 sm:p-5">
            <p class="text-xs font-medium text-slate-500 sm:text-sm">Net profit</p>
            <p @class(['mt-2 text-lg font-extrabold tabular-nums sm:text-2xl', 'text-rose-600 dark:text-rose-400' => $t['net'] < 0, 'text-emerald-600 dark:text-emerald-400' => $t['net'] >= 0])>{{ money($t['net']) }}</p>
            <p class="mt-1.5 text-xs text-slate-500">{{ $t['margin'] }}% margin</p>
        </div>
    </div>
    <p class="-mt-3 text-xs text-slate-500">▲▼ compare with {{ $c['prev_label'] }} up to {{ $c['upto']->copy()->subYear()->format('d M Y') }} (same point in the year).</p>

    {{-- Main chart --}}
    <div class="card p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-bold">Month by month</h3>
            <a href="{{ route('reports', ['type' => 'yearly', 'fy' => $a['fy']]) }}" wire:navigate class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">Yearly report & PDF →</a>
        </div>
        <div wire:key="combo-{{ $key }}" x-data="apexChart('combo', @js($combo))" class="-mx-2 mt-2 min-h-[340px]"></div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="card p-5">
            <h3 class="font-bold">Sales vs {{ $c['prev_label'] }}</h3>
            <div wire:key="cmp-{{ $key }}" x-data="apexChart('compare', @js($compare))" class="-mx-2 mt-2 min-h-[280px]"></div>
        </div>
        <div class="card p-5">
            <h3 class="font-bold">Profit building up through the year</h3>
            <div wire:key="cum-{{ $key }}" x-data="apexChart('cumulative', @js($cumulative))" class="-mx-2 mt-2 min-h-[260px]"></div>
        </div>
        <div class="card p-5">
            <h3 class="font-bold">Month-on-month sales growth</h3>
            <div wire:key="grw-{{ $key }}" x-data="apexChart('growth', @js($growth))" class="-mx-2 mt-2 min-h-[260px]"></div>
        </div>
        <div class="card p-5">
            <h3 class="font-bold">Average sales by day of week</h3>
            <div wire:key="wk-{{ $key }}" x-data="apexChart('bars', @js($week))" class="-mx-2 mt-2 min-h-[240px]"></div>
        </div>
        <div class="card p-5">
            <h3 class="font-bold">Where the money went</h3>
            @if (count($a['categories']))
                <div wire:key="cat-{{ $key }}" x-data="apexChart('split', @js($cats))" class="mt-2 min-h-[280px]"></div>
            @else
                <p class="grid h-[260px] place-items-center text-sm text-slate-500">No expenses this year</p>
            @endif
        </div>
        <div class="card p-5">
            <h3 class="font-bold">How customers paid</h3>
            @if ($t['sales'] > 0)
                <div wire:key="mode-{{ $key }}" x-data="apexChart('split', @js($modes))" class="mt-2 min-h-[280px]"></div>
            @else
                <p class="grid h-[260px] place-items-center text-sm text-slate-500">No sales this year</p>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Insights --}}
        <div class="card p-5 lg:col-span-1">
            <h3 class="flex items-center gap-2 font-bold"><x-icon name="spark" class="size-5 text-brand-500" /> Insights</h3>
            <ul class="mt-4 space-y-3 text-sm">
                @foreach ($a['insights'] as $line)
                    <li class="flex gap-2"><span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-brand-500"></span><span>{{ $line }}</span></li>
                @endforeach
            </ul>
        </div>

        {{-- Best / worst days --}}
        @foreach ([['Best days', $a['best_days'], 'text-emerald-600 dark:text-emerald-400'], ['Slowest days', $a['worst_days'], 'text-rose-600 dark:text-rose-400']] as [$title, $days, $cls])
            <div class="card overflow-hidden">
                <h3 class="border-b border-slate-100 px-5 py-4 font-bold dark:border-slate-800">{{ $title }}</h3>
                @forelse ($days as $i => $d)
                    <a href="{{ route('collections', ['m' => $d->date->format('Y-m')]) }}" wire:navigate class="flex items-center gap-3 border-b border-slate-100 px-5 py-2.5 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/40">
                        <span class="grid size-7 place-items-center rounded-lg bg-slate-100 text-xs font-bold text-slate-500 dark:bg-slate-800">{{ $i + 1 }}</span>
                        <span class="min-w-0 flex-1 text-sm"><b>{{ $d->date->format('d M Y') }}</b> <span class="text-slate-500">· {{ $d->date->format('l') }}</span></span>
                        <span class="font-bold tabular-nums {{ $cls }}">{{ money($d->total) }}</span>
                    </a>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-slate-500">No sales yet.</p>
                @endforelse
            </div>
        @endforeach
    </div>

    {{-- Month table --}}
    <div class="card overflow-hidden">
        <h3 class="border-b border-slate-100 px-5 py-4 font-bold dark:border-slate-800">Monthly figures</h3>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left">Month</th>
                        <th class="px-4 py-3 text-right">Sales</th>
                        <th class="px-4 py-3 text-right">Expenses</th>
                        <th class="px-4 py-3 text-right">Staff</th>
                        <th class="px-4 py-3 text-right">Net profit</th>
                        <th class="px-4 py-3 text-right">MoM</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($a['months'] as $i => $r)
                        <tr @class(['opacity-40' => $r['future']])>
                            <td class="px-4 py-2.5 font-semibold">{{ $r['full'] }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ money($r['sales']) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ money($r['expenses']) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ money($r['staff']) }}</td>
                            <td @class(['px-4 py-2.5 text-right font-bold tabular-nums', 'text-rose-600 dark:text-rose-400' => $r['net'] < 0, 'text-emerald-600 dark:text-emerald-400' => $r['net'] > 0])>{{ money($r['net']) }}</td>
                            <td class="px-4 py-2.5 text-right">{!! $badge($a['mom'][$i]) ?: '<span class="text-slate-400">—</span>' !!}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-slate-50 font-bold dark:bg-slate-800/50">
                    <tr>
                        <td class="px-4 py-3">{{ $a['label'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ money($t['sales']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ money($t['expenses']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ money($t['staff']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ money($t['net']) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
