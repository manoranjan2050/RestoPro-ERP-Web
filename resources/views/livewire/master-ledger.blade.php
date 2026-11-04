@php
    $srcStyle = [
        'sale' => ['bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400', 'cash'],
        'expense' => ['bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400', 'receipt'],
        'vendor_payment' => ['bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-400', 'truck'],
        'staff_payment' => ['bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-400', 'users'],
    ];
    $accStyle = [
        'cash' => ['bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400', 'cash'],
        'bank' => ['bg-sky-100 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400', 'trending'],
        'online' => ['bg-amber-100 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400', 'cloud'],
    ];
    $chip = fn ($on) => $on
        ? 'bg-white text-brand-600 shadow-sm dark:bg-slate-900 dark:text-brand-400'
        : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200';
@endphp
<div class="space-y-6">
    {{-- Filters --}}
    <div class="card p-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-full sm:w-auto">
                <label class="label" for="l-preset">Period</label>
                <select id="l-preset" wire:model.live="preset" class="input sm:w-48">
                    @foreach ($presets as $k => $label) <option value="{{ $k }}">{{ $label }}</option> @endforeach
                </select>
            </div>
            <div class="grid w-full grid-cols-2 gap-3 sm:w-auto">
                <div>
                    <label class="label" for="l-from">From</label>
                    <input id="l-from" type="date" wire:model.live="from" class="input dark:[color-scheme:dark]">
                </div>
                <div>
                    <label class="label" for="l-to">To</label>
                    <input id="l-to" type="date" wire:model.live="to" class="input dark:[color-scheme:dark]">
                </div>
            </div>
            <a href="{{ route('ledger.export', $f) }}" class="btn-ghost sm:ml-auto"><x-icon name="doc" class="size-4" /> Export CSV</a>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <div class="inline-flex flex-wrap rounded-xl border border-slate-200 bg-slate-50 p-1 dark:border-slate-700 dark:bg-slate-800">
                <button wire:click="$set('source', '')" class="rounded-lg px-3 py-1 text-sm font-semibold {{ $chip($source === '') }}">All</button>
                @foreach (\App\Services\MasterLedger::SOURCES as $k => $label)
                    <button wire:click="$set('source', '{{ $k }}')" class="rounded-lg px-3 py-1 text-sm font-semibold {{ $chip($source === $k) }}">{{ $label }}</button>
                @endforeach
            </div>
            <select wire:model.live="account" class="input w-auto py-1.5">
                <option value="">All accounts</option>
                @foreach (\App\Services\MasterLedger::ACCOUNTS as $k => $label) <option value="{{ $k }}">{{ $label }}</option> @endforeach
            </select>
            <div class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1 dark:border-slate-700 dark:bg-slate-800">
                @foreach (['' => 'In & out', 'in' => 'Money in', 'out' => 'Money out'] as $k => $label)
                    <button wire:click="$set('direction', '{{ $k }}')" class="rounded-lg px-3 py-1 text-sm font-semibold {{ $chip($direction === $k) }}">{{ $label }}</button>
                @endforeach
            </div>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search…" class="input w-full py-1.5 sm:w-48">
            @if ($filtersOn) <button wire:click="clearFilters" class="text-sm font-semibold text-brand-600 hover:underline dark:text-brand-400">Clear</button> @endif
        </div>
    </div>

    {{-- Totals for the period --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-stat label="Money in" :value="money($summary['in'])" icon="trending" tone="emerald" :hint="\Illuminate\Support\Carbon::parse($f['from'])->format('d M').' – '.\Illuminate\Support\Carbon::parse($f['to'])->format('d M Y')" />
        <x-stat label="Money out" :value="money($summary['out'])" icon="receipt" tone="rose" :hint="'Expenses, vendors, staff'" />
        <x-stat label="Net flow" :value="money($summary['net'])" icon="spark" :tone="$summary['net'] < 0 ? 'rose' : 'violet'" :hint="$summary['net'] >= 0 ? 'More came in than went out' : 'More went out than came in'" />
        <x-stat label="Closing balance" :value="money($closing)" icon="book" tone="sky" :hint="'Opening '.money($opening)" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Account balances --}}
        <div class="card p-5">
            <h3 class="font-bold">Running totals by account</h3>
            <p class="mt-1 text-xs text-slate-500">All money in − out up to {{ \Illuminate\Support\Carbon::parse($f['to'])->format('d M Y') }}</p>
            <div class="mt-4 space-y-3">
                @foreach (\App\Services\MasterLedger::ACCOUNTS as $k => $label)
                    @php [$tone, $icon] = $accStyle[$k]; $a = $summary['accounts'][$k]; @endphp
                    <div class="rounded-2xl border border-slate-200 p-3 dark:border-slate-800">
                        <div class="flex items-center gap-3">
                            <span class="grid size-9 place-items-center rounded-xl {{ $tone }}"><x-icon :name="$icon" class="size-4" /></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold">{{ $label }}</p>
                                <p class="text-xs text-slate-500">This period: <span class="text-emerald-600">+{{ money($a['in']) }}</span> · <span class="text-rose-600">−{{ money($a['out']) }}</span></p>
                            </div>
                            <p @class(['font-extrabold tabular-nums', 'text-rose-600 dark:text-rose-400' => $balances[$k] < 0])>{{ money($balances[$k]) }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-xs text-slate-500">Online = platform sales (Swiggy/Zomato type). Unpaid vendor bills and staff deductions aren't money movements, so they're not here.</p>
        </div>

        {{-- By module --}}
        <div class="card p-5 lg:col-span-2">
            <h3 class="font-bold">By module · this period</h3>
            @php $maxSrc = max(array_map(fn ($s) => max($s['in'], $s['out']), $summary['sources']) ?: [1]) ?: 1; @endphp
            <div class="mt-4 space-y-4">
                @foreach (\App\Services\MasterLedger::SOURCES as $k => $label)
                    @php $s = $summary['sources'][$k]; $v = $s['in'] ?: $s['out']; @endphp
                    <button wire:click="$set('source', '{{ $source === $k ? '' : $k }}')" class="block w-full text-left">
                        <div class="flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2 font-semibold">
                                <span class="grid size-7 place-items-center rounded-lg {{ $srcStyle[$k][0] }}"><x-icon :name="$srcStyle[$k][1]" class="size-3.5" /></span>
                                {{ $label }}
                            </span>
                            <span @class(['font-bold tabular-nums', 'text-emerald-600 dark:text-emerald-400' => $s['in'] > 0, 'text-rose-600 dark:text-rose-400' => $s['out'] > 0])>
                                {{ $s['in'] > 0 ? '+' : ($s['out'] > 0 ? '−' : '') }}{{ money($v) }}
                            </span>
                        </div>
                        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div @class(['h-full rounded-full', 'bg-emerald-500' => $s['in'] > 0, 'bg-rose-500' => $s['in'] <= 0]) style="width: {{ round($v / $maxSrc * 100) }}%"></div>
                        </div>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Timeline --}}
    <div class="card overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <h3 class="font-bold">Transactions <span class="text-sm font-medium text-slate-500">({{ $rows->count() }})</span></h3>
            <span class="text-xs text-slate-500">Newest first · tap a row to open it</span>
        </div>
        @if ($rows->isEmpty())
            <p class="px-5 py-12 text-center text-sm text-slate-500">No money movements {{ $filtersOn ? 'match these filters' : 'in this period' }}.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Particulars</th>
                            <th class="px-4 py-3">Account</th>
                            <th class="px-4 py-3 text-right">In</th>
                            <th class="px-4 py-3 text-right">Out</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($rows as $r)
                            <tr wire:key="{{ $r['key'] }}" class="cursor-pointer hover:bg-slate-50/70 dark:hover:bg-slate-800/40" onclick="Livewire.navigate('{{ $r['url'] }}')">
                                <td class="whitespace-nowrap px-4 py-2.5">{{ $r['date']->format('d M Y') }}</td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center gap-2.5">
                                        <span class="grid size-7 shrink-0 place-items-center rounded-lg {{ $srcStyle[$r['source']][0] }}"><x-icon :name="$srcStyle[$r['source']][1]" class="size-3.5" /></span>
                                        <div class="min-w-0">
                                            <p class="truncate font-medium">{{ $r['title'] }}</p>
                                            @if ($r['sub']) <p class="max-w-72 truncate text-xs text-slate-500">{{ $r['sub'] }}</p> @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-500">{{ \App\Services\MasterLedger::ACCOUNTS[$r['account']] }}{{ $r['mode'] && ! in_array($r['mode'], ['cash', 'online', 'upi']) ? ' · '.strtoupper($r['mode']) : '' }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $r['in'] ? money($r['in']) : '' }}</td>
                                <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ $r['out'] ? money($r['out']) : '' }}</td>
                                <td @class(['px-4 py-2.5 text-right tabular-nums', 'text-rose-600 dark:text-rose-400' => $r['balance'] < 0])>{{ money($r['balance']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="bg-slate-50/60 dark:bg-slate-800/30">
                            <td class="px-4 py-2.5 text-slate-500">{{ \Illuminate\Support\Carbon::parse($f['from'])->format('d M Y') }}</td>
                            <td class="px-4 py-2.5 font-medium" colspan="4">Opening balance (everything before this period)</td>
                            <td class="px-4 py-2.5 text-right font-bold tabular-nums">{{ money($opening) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="bg-slate-50 font-bold dark:bg-slate-800/50">
                        <tr>
                            <td class="px-4 py-3" colspan="3">Period total</td>
                            <td class="px-4 py-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ money($rows->sum('in')) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-rose-600 dark:text-rose-400">{{ money($rows->sum('out')) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ money($closing) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
</div>
