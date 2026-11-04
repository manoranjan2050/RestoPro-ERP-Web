@php
    $statusBadge = [
        'paid' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
        'partial' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
        'unpaid' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400',
    ];
    $color = $vendor->defaultCategory->color ?? '#64748b';
@endphp
<div class="space-y-6">
    <a href="{{ route('vendors') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-800 dark:hover:text-slate-200">‹ All vendors</a>

    {{-- Header --}}
    <div class="card overflow-hidden">
        <div class="h-2" style="background: {{ $color }}"></div>
        <div class="flex flex-col gap-5 p-5 sm:flex-row sm:items-center">
            <div class="flex min-w-0 flex-1 items-center gap-4">
                <span class="grid size-14 shrink-0 place-items-center rounded-2xl text-xl font-extrabold text-white shadow-lg" style="background: {{ $color }}">{{ mb_strtoupper(mb_substr($vendor->name, 0, 1)) }}</span>
                <div class="min-w-0">
                    <h2 class="truncate text-xl font-extrabold">{{ $vendor->name }}</h2>
                    <p class="truncate text-sm text-slate-500">
                        {{ $vendor->defaultCategory->name ?? 'Vendor' }}
                        @if ($vendor->mobile) · <a href="tel:{{ $vendor->mobile }}" class="hover:underline">{{ $vendor->mobile }}</a> @endif
                        @if ($vendor->address) · {{ $vendor->address }} @endif
                    </p>
                </div>
            </div>
            <div class="flex flex-col items-start gap-3 sm:items-end">
                <div class="sm:text-right">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $balance > 0 ? 'You owe' : ($balance < 0 ? 'Advance paid' : 'Balance') }}</p>
                    <p @class(['text-3xl font-extrabold tabular-nums', 'text-rose-600 dark:text-rose-400' => $balance > 0, 'text-emerald-600 dark:text-emerald-400' => $balance <= 0])>{{ money(abs($balance)) }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($waLink)
                        <a href="{{ $waLink }}" target="_blank" rel="noopener" class="btn-ghost py-2"><svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm5.3 14.2c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .1-3.3-.8-2.8-1.1-4.5-3.9-4.7-4.1-.1-.2-1.1-1.5-1.1-2.8 0-1.4.7-2 1-2.3.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.3.5-.4.4c-.1.1-.3.3-.1.6.2.3.8 1.3 1.6 2 1.1 1 2 1.3 2.3 1.4.3.1.5.1.6-.1l.9-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.2.1.7-.1 1.2Z"/></svg> WhatsApp</a>
                    @endif
                    <button wire:click="pay" class="btn-primary py-2"><x-icon name="cash" class="size-4" /> Record payment</button>
                </div>
            </div>
        </div>
        <div class="grid grid-cols-2 border-t border-slate-100 text-center sm:grid-cols-4 dark:border-slate-800">
            @foreach ([
                ['Opening balance', $vendor->opening_balance],
                ['Total billed', $totals['billed']],
                ['Paid at purchase', $totals['paidAtPurchase']],
                ['Khata payments', $totals['payments']],
            ] as [$label, $value])
                <div class="border-slate-100 p-3 odd:border-r sm:border-r sm:last:border-r-0 dark:border-slate-800">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</p>
                    <p class="mt-0.5 font-bold tabular-nums">{{ money($value) }}</p>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Open bills --}}
    @if ($open->isNotEmpty())
        <div class="card p-5">
            <div class="flex items-center justify-between">
                <h3 class="font-bold">Open bills <span class="text-sm font-medium text-slate-500">({{ $open->count() }})</span></h3>
                <span class="text-xs text-slate-500">Payments settle the oldest bill first</span>
            </div>
            <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                @foreach ($open as $b)
                    <div wire:key="open-{{ $b->id }}" class="min-w-36 shrink-0 rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                        <p class="text-xs text-slate-500">{{ $b->date->format('d M') }} · {{ $b->category->name }}</p>
                        <p class="mt-1 font-bold tabular-nums text-rose-600 dark:text-rose-400">{{ money($b->balance()) }}</p>
                        @if ($b->status === 'partial') <p class="text-[11px] text-slate-500">of {{ money($b->amount) }}</p> @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Statement --}}
    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
            <h3 class="w-full self-center font-bold sm:mr-auto sm:w-auto">Statement</h3>
            <div class="grid flex-1 grid-cols-2 gap-3 sm:flex-none">
                <div>
                    <label class="label text-xs" for="k-from">From</label>
                    <input id="k-from" type="date" wire:model.live="from" class="input py-1.5 dark:[color-scheme:dark]">
                </div>
                <div>
                    <label class="label text-xs" for="k-to">To</label>
                    <input id="k-to" type="date" wire:model.live="to" class="input py-1.5 dark:[color-scheme:dark]">
                </div>
            </div>
            @if ($filtered) <button wire:click="clearRange" class="pb-2 text-sm font-semibold text-brand-600 hover:underline dark:text-brand-400">All time</button> @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[620px] text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Particulars</th>
                        <th class="px-4 py-3 text-right">Bill</th>
                        <th class="px-4 py-3 text-right">Paid</th>
                        <th class="px-4 py-3 text-right">Balance</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr class="bg-slate-50/50 dark:bg-slate-800/20">
                        <td class="px-4 py-2.5 text-slate-500">{{ $filtered && $from ? \Illuminate\Support\Carbon::parse($from)->format('d M Y') : '—' }}</td>
                        <td class="px-4 py-2.5 font-medium" colspan="3">{{ $filtered && $from ? 'Balance brought forward' : 'Opening balance' }}</td>
                        <td class="px-4 py-2.5 text-right font-bold tabular-nums">{{ money($statement['opening']) }}</td>
                        <td></td>
                    </tr>
                    @forelse ($statement['rows'] as $r)
                        <tr wire:key="st-{{ $r['type'] }}-{{ $r['id'] }}" @class(['hover:bg-slate-50/70 dark:hover:bg-slate-800/40', 'bg-emerald-50/40 dark:bg-emerald-500/5' => $r['type'] === 'payment'])>
                            <td class="whitespace-nowrap px-4 py-2.5">{{ $r['date']->format('d M Y') }}</td>
                            <td class="px-4 py-2.5">
                                @if ($r['type'] === 'payment')
                                    <span class="font-semibold text-emerald-700 dark:text-emerald-400">{{ $r['title'] }}</span>
                                    <span class="text-xs text-slate-500">· {{ \App\Models\Expense::MODES[$r['mode']] ?? $r['mode'] }}</span>
                                @else
                                    <span class="font-medium">{{ $r['title'] }}</span>
                                    <span class="ml-1 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase {{ $statusBadge[$r['status']] }}">{{ $r['status'] }}</span>
                                    @if ($r['bill_path']) <a href="{{ route('expenses.bill', $r['id']) }}" target="_blank" class="ml-1 text-xs text-brand-600 underline dark:text-brand-400">bill</a> @endif
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums">{{ $r['debit'] ? money($r['debit']) : '' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ $r['credit'] ? money($r['credit']) : '' }}</td>
                            <td @class(['px-4 py-2.5 text-right font-semibold tabular-nums', 'text-rose-600 dark:text-rose-400' => $r['balance'] > 0])>{{ money($r['balance']) }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                @if ($r['type'] === 'payment')
                                    <button wire:click="edit({{ $r['id'] }})" class="rounded-lg px-2 py-1 text-xs font-semibold text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">Edit</button>
                                    @can('delete-records')
                                        <button wire:click="confirmDelete({{ $r['id'] }})" class="rounded-lg px-2 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">Delete</button>
                                    @endcan
                                @else
                                    <a href="{{ route('expenses', ['v' => $vendor->id, 'm' => $r['date']->format('Y-m')]) }}" wire:navigate class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">Open</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No bills or payments {{ $filtered ? 'in this period' : 'yet' }}.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-slate-50 font-bold dark:bg-slate-800/50">
                    <tr>
                        <td class="px-4 py-3" colspan="2">Closing balance</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ money($statement['billed']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-emerald-700 dark:text-emerald-400">{{ money($statement['paid']) }}</td>
                        <td @class(['px-4 py-3 text-right tabular-nums', 'text-rose-600 dark:text-rose-400' => $statement['closing'] > 0])>{{ money($statement['closing']) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- Payment form --}}
    @if ($showForm)
        <x-modal :title="$editingId ? 'Edit payment' : 'Pay '.$vendor->name">
            <form wire:submit="save" class="space-y-4">
                @unless ($editingId)
                    <div class="flex items-center justify-between rounded-2xl bg-rose-50 px-4 py-3 dark:bg-rose-500/10">
                        <span class="text-sm font-medium text-rose-700 dark:text-rose-300">Currently owed</span>
                        <span class="text-lg font-extrabold tabular-nums text-rose-700 dark:text-rose-300">{{ money(max($balance, 0)) }}</span>
                    </div>
                @endunless
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="p-date">Date</label>
                        <input id="p-date" type="date" wire:model="date" max="{{ today()->toDateString() }}" class="input dark:[color-scheme:dark]">
                        @error('date') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="p-amount">Amount</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-400">{{ \App\Models\Setting::get('currency_symbol') }}</span>
                            <input id="p-amount" type="number" step="0.01" min="0" inputmode="decimal" wire:model="amount" autofocus class="input pl-7 tabular-nums">
                        </div>
                        @error('amount') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <span class="label">Paid via</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach (\App\Models\Expense::MODES as $k => $label)
                            <button type="button" wire:click="$set('payment_mode', '{{ $k }}')" @class([
                                'rounded-full px-3 py-1 text-xs font-semibold ring-1 transition',
                                'bg-brand-500 text-white ring-brand-500' => $payment_mode === $k,
                                'text-slate-600 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800' => $payment_mode !== $k,
                            ])>{{ $label }}</button>
                        @endforeach
                    </div>
                    @error('payment_mode') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="p-note">Note <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="p-note" wire:model="note" class="input" placeholder="e.g. Weekly settlement, UPI ref 4821">
                    @error('note') <p class="error">{{ $message }}</p> @enderror
                </div>
                <p class="text-xs text-slate-500">The payment clears the opening balance first, then the oldest unpaid bills. Paying more than you owe is kept as an advance.</p>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" wire:click="cancel" class="btn-ghost">Cancel</button>
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save changes' : 'Record payment' }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($confirmingDelete)
        <x-modal title="Delete payment?" size="max-w-sm">
            <p class="text-sm text-slate-600 dark:text-slate-300">The bills it settled will go back to unpaid. This can't be undone.</p>
            <div class="mt-5 flex justify-end gap-2">
                <button wire:click="cancel" class="btn-ghost">Cancel</button>
                <button wire:click="delete" class="btn bg-rose-600 text-white hover:bg-rose-700">Delete</button>
            </div>
        </x-modal>
    @endif
</div>
