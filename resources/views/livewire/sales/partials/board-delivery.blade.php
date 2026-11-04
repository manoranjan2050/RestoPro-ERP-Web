{{-- Delivery board: one column per step --}}
@php $steps = \App\Models\Order::STAGES['delivery']; $steps['delivered'] = 'Delivered · to collect'; @endphp
<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
    @foreach ($steps as $stage => $label)
        <section wire:key="col-{{ $stage }}" class="rounded-2xl bg-slate-100/70 p-3 dark:bg-slate-900/60">
            <h3 class="mb-3 flex items-center justify-between px-1 text-sm font-bold">{{ $label }} <span class="text-xs font-semibold text-slate-500">{{ ($deliveries[$stage] ?? collect())->count() }}</span></h3>
            <div class="space-y-3">
                @forelse ($deliveries[$stage] ?? [] as $o)
                    <div wire:key="dl-{{ $o->id }}" class="card p-3">
                        <div class="flex items-start justify-between gap-2">
                            <a href="{{ route('orders.show', $o) }}" wire:navigate class="text-sm font-bold hover:text-brand-600">{{ $o->ref() }}</a>
                            <span class="text-sm font-extrabold tabular-nums">{{ money($o->total) }}</span>
                        </div>
                        <p class="mt-1 text-sm font-semibold">{{ $o->customer_name }} · <a href="tel:{{ $o->customer_mobile }}" class="text-brand-600">{{ $o->customer_mobile }}</a></p>
                        <p class="text-xs text-slate-500">{{ $o->delivery_address }}</p>
                        <p class="mt-1 text-xs {{ $o->balance() > 0.009 ? 'font-semibold text-amber-600' : 'text-emerald-600' }}">{{ $o->balance() > 0.009 ? 'Collect '.money($o->balance()).' on delivery' : 'Paid' }} · {{ $o->created_at->diffForHumans(null, true) }} ago</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @if ($next = $o->nextStage())
                                <button wire:click="advance({{ $o->id }})" class="btn-primary py-1 text-xs">→ {{ \App\Models\Order::STAGES['delivery'][$next] }}</button>
                            @endif
                            @if ($o->status === 'open')
                                <a href="{{ route('pos', ['order' => $o->id]) }}" wire:navigate class="btn-ghost py-1 text-xs">Settle</a>
                            @endif
                            <a href="{{ route('orders.print', $o) }}" target="_blank" class="btn-ghost py-1 text-xs">Print</a>
                        </div>
                    </div>
                @empty
                    <p class="px-1 py-4 text-center text-xs text-slate-400">—</p>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
<p class="text-xs text-slate-500">An order leaves the board when it's delivered and fully paid. New delivery orders: POS → Delivery → Save order.</p>
