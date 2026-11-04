@php
    $icons = ['monthly' => 'chart', 'yearly' => 'trending', 'sales' => 'cash', 'expenses' => 'receipt', 'vendor_dues' => 'truck', 'salary' => 'users',
        'day_end' => 'clipboard', 'item_sales' => 'tag', 'hourly' => 'trending', 'gst_summary' => 'doc', 'bills' => 'receipt'];
    $kind = $types[$type][2];
@endphp
<div class="space-y-6">
    {{-- Report picker --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        @foreach ($types as $k => [$label, $desc])
            <button wire:click="$set('type', '{{ $k }}')" @class([
                'card flex flex-col items-start gap-2 p-4 text-left transition hover:-translate-y-0.5 hover:shadow-lg',
                'ring-2 ring-brand-500' => $type === $k,
            ])>
                <span @class(['grid size-9 place-items-center rounded-xl',
                              'bg-gradient-to-br from-brand-500 to-rose-500 text-white shadow-md shadow-brand-500/25' => $type === $k,
                              'bg-slate-100 text-slate-500 dark:bg-slate-800' => $type !== $k])><x-icon :name="$icons[$k] ?? 'doc'" class="size-4" /></span>
                <span class="text-sm font-bold">{{ $label }}</span>
                <span class="text-xs text-slate-500">{{ $desc }}</span>
            </button>
        @endforeach
    </div>

    {{-- Parameters + export --}}
    <div class="card flex flex-wrap items-end gap-3 p-4">
        @if ($kind === 'month')
            <div>
                <span class="label">Month</span>
                <div class="flex items-center rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-slate-800">
                    <button wire:click="shiftMonth(-1)" class="rounded-lg px-2.5 py-1 hover:bg-slate-100 dark:hover:bg-slate-700" aria-label="Previous month">‹</button>
                    <input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}" class="w-40 border-0 bg-transparent py-1 text-center text-sm font-semibold focus:ring-0 dark:[color-scheme:dark]">
                    <button wire:click="shiftMonth(1)" @disabled($month >= now()->format('Y-m')) class="rounded-lg px-2.5 py-1 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-700" aria-label="Next month">›</button>
                </div>
            </div>
        @elseif ($kind === 'day')
            <div>
                <span class="label">Day</span>
                <div class="flex items-center rounded-xl border border-slate-200 bg-white p-1 dark:border-slate-700 dark:bg-slate-800">
                    <button wire:click="shiftDay(-1)" class="rounded-lg px-2.5 py-1 hover:bg-slate-100 dark:hover:bg-slate-700" aria-label="Previous day">‹</button>
                    <input type="date" wire:model.live="date" max="{{ today()->toDateString() }}" class="w-40 border-0 bg-transparent py-1 text-center text-sm font-semibold focus:ring-0 dark:[color-scheme:dark]">
                    <button wire:click="shiftDay(1)" @disabled($date >= today()->toDateString()) class="rounded-lg px-2.5 py-1 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-700" aria-label="Next day">›</button>
                </div>
            </div>
        @elseif ($kind === 'fy')
            <div>
                <label class="label" for="r-fy">Financial year</label>
                <select id="r-fy" wire:model.live="fy" class="input w-44">
                    @foreach ($years as $y => $l) <option value="{{ $y }}">{{ $l }}</option> @endforeach
                </select>
            </div>
        @elseif ($kind === 'range')
            <div class="grid w-full grid-cols-2 gap-3 sm:w-auto">
                <div><label class="label" for="r-from">From</label><input id="r-from" type="date" wire:model.live="from" class="input dark:[color-scheme:dark]"></div>
                <div><label class="label" for="r-to">To</label><input id="r-to" type="date" wire:model.live="to" class="input dark:[color-scheme:dark]"></div>
            </div>
            <div class="flex flex-wrap gap-1.5">
                @foreach (['month' => 'This month', 'last_month' => 'Last month', 'fy' => 'This FY'] as $k => $l)
                    <button wire:click="range('{{ $k }}')" class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300">{{ $l }}</button>
                @endforeach
            </div>
        @else
            <p class="text-sm text-slate-500">Current balances as of today.</p>
        @endif

        <div class="flex w-full gap-2 sm:ml-auto sm:w-auto">
            @if ($type === 'day_end')
                <a href="{{ route('reports.day-end.print', $params) }}" target="_blank" class="btn-ghost flex-1 sm:flex-none">🖨️ Thermal</a>
            @endif
            <a href="{{ route('reports.download', ['type' => $type, 'format' => 'pdf', 'view' => 1] + $params) }}" target="_blank" class="btn-ghost flex-1 sm:flex-none"><x-icon name="eye" class="size-4" /> View / print</a>
            <a href="{{ route('reports.download', ['type' => $type, 'format' => 'pdf'] + $params) }}" class="btn-primary flex-1 sm:flex-none"><x-icon name="doc" class="size-4" /> PDF</a>
            <a href="{{ route('reports.download', ['type' => $type, 'format' => 'xlsx'] + $params) }}" class="btn-ghost flex-1 sm:flex-none"><x-icon name="chart" class="size-4" /> Excel</a>
        </div>
    </div>

    {{-- Preview --}}
    <div wire:loading.class="opacity-50" class="space-y-6 transition">
        <div>
            <h2 class="text-xl font-extrabold">{{ $report['title'] }}</h2>
            <p class="text-sm text-slate-500">{{ $report['subtitle'] }} · {{ \App\Models\Setting::get('business_name') }}</p>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            @foreach ($report['kpis'] as [$label, $value, $t])
                <div class="card p-4">
                    <p class="truncate text-xs font-medium text-slate-500">{{ $label }}</p>
                    <p @class(['mt-1 text-lg font-extrabold tabular-nums', 'text-rose-600 dark:text-rose-400' => is_numeric($value) && $value < 0])>{{ report_cell($value, $t) ?: '—' }}</p>
                </div>
            @endforeach
        </div>

        @foreach ($report['sections'] as $section)
            <div class="card overflow-hidden">
                <h3 class="border-b border-slate-100 px-5 py-3 font-bold dark:border-slate-800">{{ $section['title'] }} <span class="text-sm font-normal text-slate-500">({{ count($section['rows']) }})</span></h3>
                @if (empty($section['rows']))
                    <p class="px-5 py-8 text-center text-sm text-slate-500">Nothing in this period.</p>
                @else
                    <div class="max-h-[32rem] overflow-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800">
                                <tr>
                                    @foreach ($section['columns'] as [$col, $t])
                                        <th class="whitespace-nowrap px-4 py-2.5 {{ in_array($t, ['text', 'date']) ? 'text-left' : 'text-right' }}">{{ $col }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($section['rows'] as $row)
                                    <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                        @foreach ($section['columns'] as $i => [$col, $t])
                                            <td @class(['whitespace-nowrap px-4 py-2 tabular-nums', 'text-right' => ! in_array($t, ['text', 'date']),
                                                        'text-rose-600 dark:text-rose-400' => $t === 'money' && is_numeric($row[$i]) && $row[$i] < 0])>{{ report_cell($row[$i], $t) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                            @if ($section['totals'])
                                <tfoot class="sticky bottom-0 bg-slate-50 font-bold dark:bg-slate-800">
                                    <tr>
                                        @foreach ($section['columns'] as $i => [$col, $t])
                                            <td class="whitespace-nowrap px-4 py-2.5 tabular-nums {{ in_array($t, ['text', 'date']) ? '' : 'text-right' }}">{{ report_cell($section['totals'][$i], $t) }}</td>
                                        @endforeach
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                @endif
            </div>
        @endforeach

        @foreach ($report['notes'] as $n)
            <p class="text-xs text-slate-500">• {{ $n }}</p>
        @endforeach
    </div>
</div>
