@php
    $split = ['labels' => ['Cash', 'UPI', 'Online'], 'values' => [round($totals['cash'], 2), round($totals['upi'], 2), round($totals['online'], 2)]];
    $chartKey = md5(json_encode([$series, $split]));
@endphp
<div class="space-y-6">
    {{-- Filters --}}
    <div class="card p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-full sm:w-auto">
                <label class="label" for="r-preset">Period</label>
                <select id="r-preset" wire:model.live="preset" class="input sm:w-48">
                    @foreach ($presets as $k => $label)
                        <option value="{{ $k }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid w-full grid-cols-2 gap-3 sm:w-auto">
                <div>
                    <label class="label" for="r-from">From</label>
                    <input id="r-from" type="date" wire:model.live="from" class="input dark:[color-scheme:dark]">
                </div>
                <div>
                    <label class="label" for="r-to">To</label>
                    <input id="r-to" type="date" wire:model.live="to" class="input dark:[color-scheme:dark]">
                </div>
            </div>
            <div>
                <span class="label">Group by</span>
                <div class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1 dark:border-slate-700 dark:bg-slate-800">
                    @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $g => $label)
                        <button wire:click="$set('group', '{{ $g }}')" @class([
                            'rounded-lg px-3 py-1.5 text-sm font-semibold transition',
                            'bg-white text-brand-600 shadow-sm dark:bg-slate-900 dark:text-brand-400' => $group === $g,
                            'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' => $group !== $g,
                        ])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <a href="{{ route('revenue.export', ['from' => $report->from->toDateString(), 'to' => $report->to->toDateString(), 'group' => $group]) }}"
               class="btn-ghost sm:ml-auto"><x-icon name="doc" class="size-4" /> Export CSV</a>
        </div>
        <p class="mt-3 text-xs text-slate-500">{{ $report->from->format('d M Y') }} → {{ $report->to->format('d M Y') }}</p>
    </div>

    {{-- KPIs --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-stat label="Total revenue" :value="money($totals['total'])" icon="spark" tone="violet" :hint="$totals['days'].' day(s) with sales'" />
        <x-stat label="Average / day" :value="money($avg, false)" icon="trending" tone="sky" hint="Across days with an entry" />
        <x-stat label="Best {{ $group }}" :value="$best ? money($best['total']) : '—'" icon="chart" tone="emerald" :hint="$best['label'] ?? 'No data'" />
        <x-stat label="Digital share" :value="$totals['total'] ? round(($totals['upi'] + $totals['online']) / $totals['total'] * 100).'%' : '—'" icon="cloud" tone="amber" hint="UPI + online vs cash" />
    </div>

    {{-- Charts --}}
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <h3 class="font-bold">Sales trend</h3>
            <div wire:key="trend-{{ $chartKey }}" x-data="apexChart('trend', @js($series))" class="-mx-2 mt-2 min-h-[280px]"></div>
        </div>
        <div class="card p-5">
            <h3 class="font-bold">Payment mode split</h3>
            @if ($totals['total'] > 0)
                <div wire:key="split-{{ $chartKey }}" x-data="apexChart('split', @js($split))" class="mt-2 min-h-[280px]"></div>
            @else
                <p class="grid h-[280px] place-items-center text-sm text-slate-500">No sales in this period</p>
            @endif
        </div>
    </div>

    {{-- Table --}}
    <div class="card overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <h3 class="font-bold">Revenue by {{ $group }}</h3>
        </div>
        @if ($rows->isEmpty())
            <p class="px-5 py-12 text-center text-sm text-slate-500">No collections recorded in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr>
                            <th class="px-5 py-3">Period</th>
                            <th class="px-5 py-3 text-right">Cash</th>
                            <th class="px-5 py-3 text-right">UPI</th>
                            <th class="px-5 py-3 text-right">Online</th>
                            <th class="px-5 py-3 text-right">Total</th>
                            <th class="w-40 px-5 py-3">Share</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @php $max = max($rows->max('total'), 1); @endphp
                        @foreach ($rows as $r)
                            <tr wire:key="rev-{{ $r['key'] }}" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                <td class="px-5 py-3"><p class="font-semibold">{{ $r['label'] }}</p><p class="text-xs text-slate-500">{{ $r['sub'] }}</p></td>
                                <td class="px-5 py-3 text-right tabular-nums">{{ money($r['cash']) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums">{{ money($r['upi']) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums">{{ money($r['online']) }}</td>
                                <td class="px-5 py-3 text-right font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ money($r['total']) }}</td>
                                <td class="px-5 py-3">
                                    <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                        <div class="h-full rounded-full bg-gradient-to-r from-brand-400 to-rose-500" style="width: {{ round($r['total'] / $max * 100) }}%"></div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 font-bold dark:bg-slate-800/50">
                        <tr>
                            <td class="px-5 py-3">Total</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ money($totals['cash']) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ money($totals['upi']) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ money($totals['online']) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ money($totals['total']) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
</div>
