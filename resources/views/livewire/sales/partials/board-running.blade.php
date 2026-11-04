{{-- Running dine-in bills (open) --}}
<div class="card overflow-hidden">
    @forelse ($running as $o)
        <div wire:key="run-{{ $o->id }}" class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
            <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-brand-500 to-rose-500 text-sm font-extrabold text-white">{{ $o->table?->name ?? '—' }}</span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold">{{ $o->ref() }} · {{ money($o->total) }}</p>
                <p class="text-xs text-slate-500">{{ $o->items_count }} item(s){{ $o->guests ? ' · '.$o->guests.' guests' : '' }} · open {{ $o->created_at->diffForHumans(null, true) }}{{ $o->customer_name ? ' · '.$o->customer_name : '' }}</p>
            </div>
            <a href="{{ route('orders.print', $o) }}" target="_blank" class="btn-ghost py-1.5 text-sm">Print bill</a>
            <a href="{{ route('pos', ['order' => $o->id]) }}" wire:navigate class="btn-primary py-1.5 text-sm">Open / settle</a>
        </div>
    @empty
        <p class="px-6 py-14 text-center text-sm text-slate-500">No table has a running bill. Start one from <a href="{{ route('tables') }}" wire:navigate class="font-semibold text-brand-600">Tables</a>.</p>
    @endforelse
</div>
