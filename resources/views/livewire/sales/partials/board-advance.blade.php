{{-- Advance / custom orders, by pickup day --}}
@php $tone = ['Overdue' => 'text-rose-600', 'Today' => 'text-brand-600', 'Tomorrow' => 'text-amber-600', 'Later' => 'text-slate-500']; @endphp
@forelse ($advances as $group => $list)
    <section wire:key="adv-{{ $group }}">
        <h3 class="mb-2 text-sm font-bold {{ $tone[$group] ?? '' }}">{{ $group }} <span class="font-semibold text-slate-400">· {{ $list->count() }}</span></h3>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($list as $o)
                <div wire:key="ad-{{ $o->id }}" class="card p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <a href="{{ route('orders.show', $o) }}" wire:navigate class="font-bold hover:text-brand-600">{{ $o->customer_name }}</a>
                            <p class="text-xs text-slate-500">{{ $o->ref() }} · <a href="tel:{{ $o->customer_mobile }}" class="text-brand-600">{{ $o->customer_mobile }}</a></p>
                        </div>
                        <span class="rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-violet-700 dark:bg-violet-500/15 dark:text-violet-300">{{ $o->stageLabel() }}</span>
                    </div>
                    <p class="mt-2 text-sm font-semibold">🕒 {{ $o->due_at?->format('D d M, h:i A') }}</p>
                    <p class="mt-1 text-sm">{{ $o->items->map(fn ($l) => $l->qtyLabel().'× '.$l->label())->implode(', ') }}</p>
                    @if ($o->details)
                        <div class="mt-2 space-y-0.5 rounded-xl bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                            @foreach (\App\Models\Order::CAKE_FIELDS as $k => $label)
                                @if (! empty($o->details[$k]))<p><b>{{ $label }}:</b> {{ $k === 'eggless' ? 'Yes' : $o->details[$k] }}</p>@endif
                            @endforeach
                        </div>
                    @endif
                    <p class="mt-2 text-sm">
                        <b class="tabular-nums">{{ money($o->total) }}</b>
                        <span class="text-xs text-slate-500">· advance {{ money($o->paid) }}</span>
                        @if ($o->balance() > 0.009)<span class="text-xs font-semibold text-amber-600">· balance {{ money($o->balance()) }}</span>@endif
                    </p>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        @if ($next = $o->nextStage())
                            <button wire:click="advance({{ $o->id }})" class="btn-ghost py-1 text-xs">→ {{ \App\Models\Order::STAGES['advance'][$next] }}</button>
                        @endif
                        @if ($o->status === 'open')
                            <a href="{{ route('pos', ['order' => $o->id]) }}" wire:navigate class="btn-primary py-1 text-xs">Collect & hand over</a>
                        @endif
                        <a href="{{ route('orders.kot', $o) }}" target="_blank" class="btn-ghost py-1 text-xs">Kitchen slip</a>
                        @if ($wa = \App\Services\BillView::whatsappUrl($o))<a href="{{ $wa }}" target="_blank" rel="noopener" class="btn-ghost py-1 text-xs text-emerald-600">WhatsApp</a>@endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@empty
    <div class="card px-6 py-14 text-center text-sm text-slate-500">No advance orders waiting. Book one from POS → Advance order.</div>
@endforelse
