@php
    $statusBadge = [
        'completed' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        'open' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
        'cancelled' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
    ];
@endphp
<div class="space-y-5" @if ($tab !== 'bills') wire:poll.30s @endif>
    @if (count($tabs) > 1)
        <div class="flex max-w-full overflow-x-auto">
            <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                @foreach ($tabs as $k => $l)
                    <button wire:click="$set('tab', '{{ $k }}')" @class(['flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-semibold',
                        'bg-gradient-to-r from-brand-500 to-rose-500 text-white shadow' => $tab === $k,
                        'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' => $tab !== $k])>
                        {{ $l }}
                        @if (($counts[$k] ?? 0) > 0)<span @class(['rounded-full px-1.5 text-xs', 'bg-white/25' => $tab === $k, 'bg-brand-100 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' => $tab !== $k])>{{ $counts[$k] }}</span>@endif
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @if ($tab === 'running')
        @include('livewire.sales.partials.board-running')
    @elseif ($tab === 'delivery')
        @include('livewire.sales.partials.board-delivery')
    @elseif ($tab === 'advance')
        @include('livewire.sales.partials.board-advance')
    @else
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-1">
            <button wire:click="shiftDay(-1)" class="btn-ghost px-2.5" aria-label="Previous day">‹</button>
            <input type="date" wire:model.live="date" max="{{ today()->toDateString() }}" class="input w-auto dark:[color-scheme:dark]" aria-label="Date">
            <button wire:click="shiftDay(1)" @disabled($isToday) class="btn-ghost px-2.5 disabled:opacity-40" aria-label="Next day">›</button>
        </div>
        <div class="relative min-w-0 flex-1 sm:max-w-xs">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><x-icon name="search" class="size-4" /></span>
            <input wire:model.live.debounce.300ms="search" type="search" class="input pl-9" placeholder="Bill no., name or mobile (any date)">
        </div>
        <select wire:model.live="status" class="input w-auto">
            <option value="">All bills</option>
            <option value="completed">Paid</option>
            <option value="cancelled">Cancelled</option>
        </select>
        <a href="{{ route('pos') }}" wire:navigate class="btn-primary ml-auto"><x-icon name="cart" class="size-4" /> New bill</a>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <x-stat label="Bills" :value="number_format($summary['count'])" icon="receipt" tone="sky" :hint="$summary['cancelled'] ? $summary['cancelled'].' cancelled' : 'none cancelled'" />
        <x-stat label="Sales" :value="money($summary['total'])" icon="cash" tone="emerald" :hint="$summary['count'] ? 'avg '.money($summary['total'] / $summary['count']) : '—'" />
        <x-stat label="Discounts" :value="money($summary['discount'])" icon="tag" tone="amber" hint="given on these bills" />
        <x-stat label="GST" :value="money($summary['tax'])" icon="doc" tone="violet" hint="CGST + SGST" />
    </div>

    <div class="card overflow-hidden">
        @forelse ($orders as $o)
            <a wire:key="o-{{ $o->id }}" href="{{ route('orders.show', $o) }}" wire:navigate class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/40">
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                        {{ $o->number }}
                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ $statusBadge[$o->status] ?? 'bg-slate-100 text-slate-600' }}">{{ \App\Models\Order::STATUSES[$o->status] ?? $o->status }}</span>
                        <span class="text-xs font-normal text-slate-500">{{ \App\Models\Order::TYPES[$o->type] ?? $o->type }}</span>
                    </p>
                    <p class="truncate text-xs text-slate-500">
                        {{ $o->billed_at?->format('d M, h:i A') }} · {{ $o->items_count }} item(s) · {{ $o->customer_name ?: 'Walk-in' }}{{ $o->customer_mobile ? ' · '.$o->customer_mobile : '' }}
                        · {{ $o->payments->groupBy('mode')->map(fn ($p, $m) => \App\Models\OrderPayment::MODES[$m][0] ?? $m)->implode(' + ') ?: '—' }}
                    </p>
                </div>
                <p @class(['text-base font-extrabold tabular-nums', 'text-slate-400 line-through' => $o->isCancelled()])>{{ money($o->total) }}</p>
            </a>
        @empty
            <div class="px-6 py-14 text-center text-sm text-slate-500">
                {{ $search !== '' ? 'No bill matches “'.$search.'”.' : 'No bills on '.\Illuminate\Support\Carbon::parse($date)->format('d M Y').'.' }}
            </div>
        @endforelse
    </div>
    <div>{{ $orders->links() }}</div>
    @endif
</div>
