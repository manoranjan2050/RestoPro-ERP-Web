@php $o = $order; @endphp
<div class="mx-auto max-w-3xl space-y-5">
    <a href="{{ route('orders') }}" wire:navigate class="text-sm text-slate-500 hover:text-slate-800 dark:hover:text-slate-200">‹ Orders & bills</a>

    @if ($o->isCancelled())
        <div class="rounded-2xl bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:bg-rose-500/10 dark:text-rose-300">
            <b>Cancelled</b> {{ $o->cancelled_at?->format('d M Y, h:i A') }} — {{ $o->cancel_reason }}. Its payments no longer count in the day's sales.
        </div>
    @endif

    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 p-5 dark:border-slate-800">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $o->gst_enabled ? 'Tax invoice' : 'Bill' }} · {{ \App\Models\Order::TYPES[$o->type] ?? $o->type }}</p>
                <h2 class="text-2xl font-extrabold">{{ $o->number }}</h2>
                <p class="text-sm text-slate-500">{{ $o->billed_at?->format('l, d M Y · h:i A') }}{{ $o->creator ? ' · by '.$o->creator->name : '' }}</p>
            </div>
            <div class="text-right">
                <p class="text-sm text-slate-500">Total</p>
                <p @class(['text-3xl font-extrabold tabular-nums', 'text-slate-400 line-through' => $o->isCancelled()])>{{ money($o->total) }}</p>
            </div>
        </div>

        @if ($o->customer_name || $o->customer_mobile)
            <div class="border-b border-slate-100 px-5 py-3 text-sm dark:border-slate-800">
                <span class="text-slate-500">Customer:</span> <b>{{ $o->customer_name ?: '—' }}</b>{{ $o->customer_mobile ? ' · '.$o->customer_mobile : '' }}{{ $o->customer_gstin ? ' · GSTIN '.$o->customer_gstin : '' }}
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-5 py-2 text-left">Item</th>
                        <th class="px-3 py-2 text-right">Qty</th>
                        <th class="px-3 py-2 text-right">Rate</th>
                        @if ($o->gst_enabled)<th class="px-3 py-2 text-right">GST</th>@endif
                        <th class="px-5 py-2 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($o->items as $l)
                        <tr wire:key="l-{{ $l->id }}">
                            <td class="px-5 py-2.5">
                                <p class="font-medium">{{ $l->label() }}</p>
                                @if ($l->note)<p class="text-xs italic text-amber-600">“{{ $l->note }}”</p>@endif
                                @if ($o->gst_enabled && $l->hsn)<p class="text-xs text-slate-400">HSN {{ $l->hsn }}</p>@endif
                            </td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $l->qtyLabel() }}{{ $l->unit !== 'pcs' ? ' '.$l->unit : '' }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ money($l->rate) }}</td>
                            @if ($o->gst_enabled)<td class="px-3 py-2.5 text-right tabular-nums text-slate-500">{{ (float) $l->gst_rate }}%</td>@endif
                            <td class="px-5 py-2.5 text-right font-semibold tabular-nums">{{ money($l->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="space-y-1 border-t border-slate-100 p-5 text-sm dark:border-slate-800 sm:ml-auto sm:w-80">
            <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span class="tabular-nums">{{ money($o->subtotal) }}</span></div>
            @if ((float) $o->discount > 0)
                <div class="flex justify-between"><span class="text-slate-500">Discount{{ $o->discount_type === 'pct' ? ' ('.(float) $o->discount_value.'%)' : '' }}</span><span class="tabular-nums text-rose-600">−{{ money($o->discount) }}</span></div>
            @endif
            @if ($o->gst_enabled)
                <div class="flex justify-between"><span class="text-slate-500">Taxable value</span><span class="tabular-nums">{{ money($o->taxable) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">CGST</span><span class="tabular-nums">{{ money($o->cgst) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">SGST</span><span class="tabular-nums">{{ money($o->sgst) }}</span></div>
            @endif
            @if ((float) $o->round_off != 0)
                <div class="flex justify-between"><span class="text-slate-500">Round off</span><span class="tabular-nums">{{ number_format((float) $o->round_off, 2) }}</span></div>
            @endif
            <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-extrabold dark:border-slate-700"><span>Total</span><span class="tabular-nums">{{ money($o->total) }}</span></div>
        </div>

        @if ($o->gst_enabled && $byRate->isNotEmpty())
            <div class="border-t border-slate-100 px-5 py-4 dark:border-slate-800">
                <p class="mb-2 text-xs font-semibold uppercase text-slate-500">GST summary</p>
                <table class="w-full text-xs">
                    <thead class="text-slate-500"><tr><th class="text-left">Rate</th><th class="text-right">Taxable</th><th class="text-right">CGST</th><th class="text-right">SGST</th><th class="text-right">Total tax</th></tr></thead>
                    <tbody>
                        @foreach ($byRate as $r => $v)
                            <tr><td>{{ $r }}%</td><td class="text-right tabular-nums">{{ money($v['taxable']) }}</td><td class="text-right tabular-nums">{{ money($v['tax'] / 2) }}</td><td class="text-right tabular-nums">{{ money($v['tax'] / 2) }}</td><td class="text-right tabular-nums">{{ money($v['tax']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="border-t border-slate-100 px-5 py-4 dark:border-slate-800">
            <p class="mb-2 text-xs font-semibold uppercase text-slate-500">Payments</p>
            @forelse ($o->payments as $p)
                <div class="flex justify-between text-sm"><span>{{ \App\Models\OrderPayment::MODES[$p->mode][0] ?? $p->mode }}{{ $p->reference ? ' · '.$p->reference : '' }} <span class="text-xs text-slate-400">{{ $p->date->format('d M') }}</span></span><span class="tabular-nums">{{ money($p->amount) }}</span></div>
            @empty
                <p class="text-sm text-slate-500">No payment (free / 100% discount).</p>
            @endforelse
        </div>
        @if ($o->note)<p class="border-t border-slate-100 px-5 py-3 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-300">Note: {{ $o->note }}</p>@endif
    </div>

    <div class="flex flex-wrap gap-2">
        <a href="{{ route('orders.print', $o) }}" target="_blank" class="btn-primary">🖨️ Print receipt</a>
        <a href="{{ route('orders.pdf', $o) }}?view=1" target="_blank" class="btn-ghost"><x-icon name="doc" class="size-4" /> PDF</a>
        @if ($wa = \App\Services\BillView::whatsappUrl($o))
            <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn-ghost text-emerald-600"><x-icon name="whatsapp" class="size-4" /> WhatsApp</a>
        @endif
        <button type="button" x-data="{ ok: false }" @click="navigator.clipboard.writeText('{{ \App\Services\BillView::publicUrl($o) }}'); ok = true; setTimeout(() => ok = false, 1500)" class="btn-ghost">
            <span x-text="ok ? 'Copied ✓' : 'Copy bill link'"></span>
        </button>
        <a href="{{ route('orders.kot', $o) }}" target="_blank" class="btn-ghost">KOT</a>
        @can('delete-records')
            @unless ($o->isCancelled())
                <button wire:click="askCancel" class="btn ml-auto text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">Cancel bill</button>
            @endunless
        @endcan
    </div>

    @if ($confirmCancel)
        <x-modal title="Cancel {{ $o->number }}?" close="closeCancel" size="max-w-md">
            <p class="text-sm text-slate-600 dark:text-slate-300">The bill keeps its number but is marked cancelled, and its {{ money($o->paid) }} is taken out of that day's sales. Give the money back to the customer.</p>
            <label class="label mt-4" for="cr">Reason</label>
            <input id="cr" wire:model="reason" class="input" placeholder="wrong item billed, customer returned…" autofocus>
            @error('reason') <p class="error">{{ $message }}</p> @enderror
            <div class="mt-5 flex justify-end gap-2">
                <button wire:click="closeCancel" class="btn-ghost">Keep bill</button>
                <button wire:click="cancelBill" class="btn bg-rose-600 text-white hover:bg-rose-700">Cancel bill</button>
            </div>
        </x-modal>
    @endif
</div>
