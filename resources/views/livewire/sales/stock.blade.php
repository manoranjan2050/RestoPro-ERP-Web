@php
    $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $actLabels = ['made' => 'Made today (+)', 'purchase' => 'Received / bought (+)', 'wastage' => 'Wastage / expired (−)', 'count' => 'Set counted stock'];
@endphp
<div class="space-y-5">
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Tracked items" :value="number_format($kpi['tracked'])" icon="box" tone="sky" :hint="$kpi['out'] ? $kpi['out'].' out of stock' : 'none out of stock'" />
        <x-stat label="Low stock" :value="number_format($kpi['low'])" icon="spark" tone="amber" hint="at or below the warning level" />
        <x-stat label="Made today" :value="$q($kpi['made'])" icon="plus" tone="emerald" :hint="'wastage '.$q($kpi['waste'])" />
        <x-stat label="Sold today" :value="$q($kpi['sold'])" icon="cart" tone="violet" hint="from bills, all units" />
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            @foreach (['stock' => 'Stock on hand', 'history' => 'History'] as $k => $l)
                <button wire:click="$set('tab', '{{ $k }}')" @class(['rounded-lg px-3 py-1.5 text-sm font-semibold',
                    'bg-gradient-to-r from-brand-500 to-rose-500 text-white shadow' => $tab === $k,
                    'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' => $tab !== $k])>{{ $l }}</button>
            @endforeach
        </div>
        <div class="relative min-w-0 flex-1 sm:max-w-xs">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><x-icon name="search" class="size-4" /></span>
            <input wire:model.live.debounce.300ms="search" type="search" class="input pl-9" placeholder="Search item">
        </div>
        @if ($tab === 'stock')
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="lowOnly" class="size-4 rounded border-slate-300 text-brand-500"> Low / out only</label>
        @else
            <select wire:model.live="reason" class="input w-auto">
                <option value="">All movements</option>
                @foreach (\App\Models\StockMovement::REASONS as $k => [$l])<option value="{{ $k }}">{{ $l }}</option>@endforeach
            </select>
        @endif
        <div class="ml-auto flex flex-wrap gap-2">
            <button wire:click="openTrack" class="btn-ghost">Track more items</button>
            <button wire:click="openBulk('wastage')" class="btn-ghost">Wastage</button>
            <button wire:click="openBulk('made')" class="btn-primary"><x-icon name="plus" class="size-4" /> Made today</button>
        </div>
    </div>

    @if ($tab === 'stock')
        <div class="card overflow-hidden">
            @forelse ($rows as $r)
                <div wire:key="s-{{ $r['key'] }}" @class(['flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800', 'opacity-50' => ! $r['active']])>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">{{ $r['label'] }}</p>
                        <p class="text-xs text-slate-500">{{ $r['item']->category?->name ?? 'No category' }}{{ $r['item']->low_stock_at !== null ? ' · warn at '.$q($r['item']->low_stock_at) : '' }}</p>
                    </div>
                    <p @class(['w-28 text-right text-lg font-extrabold tabular-nums',
                        'text-rose-600' => $r['qty'] <= 0, 'text-amber-600' => $r['qty'] > 0 && $r['low'], 'text-emerald-600' => $r['qty'] > 0 && ! $r['low']])>
                        {{ $q($r['qty']) }} <span class="text-xs font-semibold text-slate-400">{{ $r['unit'] }}</span>
                    </p>
                    <div class="flex gap-1">
                        <button wire:click="act('{{ $r['key'] }}', 'made')" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-500/10">+ In</button>
                        <button wire:click="act('{{ $r['key'] }}', 'wastage')" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">− Waste</button>
                        <button wire:click="act('{{ $r['key'] }}', 'count')" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Count</button>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center gap-3 px-6 py-14 text-center">
                    <span class="grid size-14 place-items-center rounded-2xl bg-sky-50 text-sky-500 dark:bg-sky-500/10"><x-icon name="box" class="size-7" /></span>
                    <h3 class="text-lg font-bold">{{ $kpi['tracked'] ? 'Nothing matches' : 'No items tracked yet' }}</h3>
                    <p class="max-w-md text-sm text-slate-500">Choose which items to count (cakes, pastries, packed goods). Stock then goes down on every bill, comes back when a bill is cancelled, and warns you when it runs low.</p>
                    @unless ($kpi['tracked'])<button wire:click="openTrack" class="btn-primary">Choose items to track</button>@endunless
                </div>
            @endforelse
        </div>
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800/50">
                        <tr><th class="px-5 py-2 text-left">When</th><th class="px-3 py-2 text-left">Item</th><th class="px-3 py-2 text-left">What</th><th class="px-3 py-2 text-right">Qty</th><th class="px-3 py-2 text-right">After</th><th class="px-5 py-2 text-left">Note</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($history as $m)
                            <tr wire:key="m-{{ $m->id }}">
                                <td class="whitespace-nowrap px-5 py-2 text-slate-500">{{ $m->created_at->format('d M, h:i A') }}</td>
                                <td class="px-3 py-2 font-medium">{{ $m->item?->name }}{{ $m->variant ? ' · '.$m->variant->name : '' }}</td>
                                <td class="px-3 py-2">{{ \App\Models\StockMovement::REASONS[$m->reason][0] ?? $m->reason }}
                                    @if ($m->order)<a href="{{ route('orders.show', $m->order) }}" wire:navigate class="text-xs text-brand-600">{{ $m->order->ref() }}</a>@endif</td>
                                <td @class(['px-3 py-2 text-right font-semibold tabular-nums', 'text-emerald-600' => $m->qty > 0, 'text-rose-600' => $m->qty < 0])>{{ $m->qty > 0 ? '+' : '' }}{{ $q($m->qty) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-slate-500">{{ $q($m->balance) }}</td>
                                <td class="px-5 py-2 text-xs text-slate-500">{{ $m->note }}{{ $m->creator ? ' · '.$m->creator->name : '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-12 text-center text-slate-500">No stock movements yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div>{{ $history->links() }}</div>
    @endif

    {{-- one row --}}
    @if ($act)
        <x-modal :title="$act['label']" close="closeAct" size="max-w-sm">
            <p class="text-sm text-slate-500">Now: <b>{{ $q($act['qty']) }} {{ $act['unit'] }}</b></p>
            <div class="mt-3 grid grid-cols-2 gap-2 text-xs font-semibold">
                @foreach ($actLabels as $k => $l)
                    <button wire:click="$set('actType', '{{ $k }}')" @class(['rounded-lg px-2 py-2 ring-1', 'bg-slate-900 text-white ring-slate-900 dark:bg-white dark:text-slate-900' => $actType === $k, 'ring-slate-200 dark:ring-slate-700' => $actType !== $k])>{{ $l }}</button>
                @endforeach
            </div>
            <form wire:submit="saveAct" class="mt-4 space-y-3">
                <div>
                    <label class="label" for="aq">{{ $actType === 'count' ? 'Counted on the shelf' : 'Quantity' }} ({{ $act['unit'] }})</label>
                    <input id="aq" wire:model="actQty" type="number" step="0.001" min="0" class="input tabular-nums" autofocus>
                    @error('actQty') <p class="error">{{ $message }}</p> @enderror
                </div>
                <input wire:model="actNote" class="input" maxlength="120" placeholder="Note (optional)">
                <div class="flex justify-end gap-2"><button type="button" wire:click="closeAct" class="btn-ghost">Cancel</button><button class="btn-primary">Save</button></div>
            </form>
        </x-modal>
    @endif

    {{-- bulk --}}
    @if ($showBulk)
        <x-modal :title="['made' => 'Made today', 'purchase' => 'Stock received', 'wastage' => 'Wastage / expired'][$bulkReason]" close="closeBulk" size="max-w-lg">
            <div class="mb-3 inline-flex rounded-lg bg-slate-100 p-0.5 text-xs font-semibold dark:bg-slate-800">
                @foreach (['made' => 'Made today', 'purchase' => 'Received', 'wastage' => 'Wastage'] as $k => $l)
                    <button wire:click="$set('bulkReason', '{{ $k }}')" @class(['rounded-md px-2.5 py-1', 'bg-white shadow dark:bg-slate-700' => $bulkReason === $k, 'text-slate-500' => $bulkReason !== $k])>{{ $l }}</button>
                @endforeach
            </div>
            <div class="max-h-[55vh] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                @forelse ($bulkRows as $r)
                    <div wire:key="b-{{ $r['key'] }}" class="flex items-center gap-3 py-2">
                        <span class="flex-1 text-sm">{{ $r['label'] }} <span class="text-xs text-slate-400">· now {{ $q($r['qty']) }}</span></span>
                        <input wire:model="bulk.{{ $r['key'] }}" type="number" step="0.001" min="0" class="input w-24 py-1.5 text-right tabular-nums" placeholder="0" aria-label="{{ $r['label'] }}">
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-slate-500">No tracked items yet.</p>
                @endforelse
            </div>
            @error('bulk') <p class="error mt-2">{{ $message }}</p> @enderror
            <div class="mt-4 flex justify-end gap-2"><button wire:click="closeBulk" class="btn-ghost">Cancel</button><button wire:click="saveBulk" class="btn-primary">Save</button></div>
        </x-modal>
    @endif

    {{-- start tracking --}}
    @if ($showTrack)
        <x-modal title="Track stock for…" close="closeTrack">
            <div class="max-h-[55vh] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                @forelse ($untracked as $i)
                    <label wire:key="tr-{{ $i->id }}" class="flex cursor-pointer items-center gap-3 py-2 text-sm">
                        <input type="checkbox" wire:model="track.{{ $i->id }}" class="size-4 rounded border-slate-300 text-brand-500"> {{ $i->name }}
                    </label>
                @empty
                    <p class="py-6 text-center text-sm text-slate-500">Every item is already tracked.</p>
                @endforelse
            </div>
            <p class="mt-3 text-xs text-slate-500">Set the low-stock warning per item on the Items page.</p>
            <div class="mt-4 flex justify-end gap-2"><button wire:click="closeTrack" class="btn-ghost">Cancel</button><button wire:click="saveTrack" class="btn-primary">Start tracking</button></div>
        </x-modal>
    @endif
</div>
