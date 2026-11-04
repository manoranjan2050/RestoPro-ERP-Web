<div class="space-y-6">
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <button wire:click="shiftMonth(-1)" class="rounded-lg px-2.5 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Previous month">‹</button>
            <input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}"
                   class="w-40 border-0 bg-transparent py-1 text-center text-sm font-semibold focus:ring-0 dark:[color-scheme:dark]">
            <button wire:click="shiftMonth(1)" @disabled($isCurrentMonth) class="rounded-lg px-2.5 py-1.5 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" aria-label="Next month">›</button>
        </div>
        <button wire:click="create" class="btn-primary ml-auto"><x-icon name="plus" class="size-4" /> Add collection</button>
    </div>

    {{-- Month totals --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-stat label="Month total" :value="money($totals['total'])" icon="spark" tone="violet" :hint="$totals['days'].' day(s) entered · avg '.money($avg, false)" />
        <x-stat label="Cash" :value="money($totals['cash'])" icon="cash" tone="emerald" :hint="$totals['total'] ? round($totals['cash'] / $totals['total'] * 100).'% of sales' : '—'" />
        <x-stat label="UPI" :value="money($totals['upi'])" icon="trending" tone="sky" :hint="$totals['total'] ? round($totals['upi'] / $totals['total'] * 100).'% of sales' : '—'" />
        <x-stat label="Online" :value="money($totals['online'])" icon="cloud" tone="amber" :hint="$totals['total'] ? round($totals['online'] / $totals['total'] * 100).'% of sales' : '—'" />
    </div>

    {{-- Missing days reminder --}}
    @if (count($missing))
        <div class="card flex flex-col gap-3 border-amber-200 bg-amber-50/70 p-4 sm:flex-row sm:items-center dark:border-amber-500/30 dark:bg-amber-500/10">
            <p class="text-sm font-medium text-amber-800 dark:text-amber-300">
                {{ count($missing) }} day(s) without an entry this month
            </p>
            <div class="flex flex-wrap gap-1.5 sm:ml-auto">
                @foreach (array_slice($missing, 0, 8) as $d)
                    <button wire:click="create('{{ $d->toDateString() }}')" class="rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-amber-700 shadow-sm ring-1 ring-amber-200 hover:bg-amber-100 dark:bg-slate-900 dark:text-amber-300 dark:ring-amber-500/30">
                        + {{ $d->format('d M') }}
                    </button>
                @endforeach
                @if (count($missing) > 8) <span class="self-center text-xs text-amber-700 dark:text-amber-300">+{{ count($missing) - 8 }} more</span> @endif
            </div>
        </div>
    @endif

    {{-- Entries --}}
    <div class="card overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <h3 class="font-bold">Daily entries</h3>
            @if ($best)
                <span class="text-xs text-slate-500">Best day: <b class="text-emerald-600 dark:text-emerald-400">{{ $best->date->format('d M') }} · {{ money($best->total) }}</b></span>
            @endif
        </div>

        @if ($entries->isEmpty())
            <div class="px-5 py-14 text-center">
                <div class="mx-auto grid size-14 place-items-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/10"><x-icon name="cash" class="size-7" /></div>
                <p class="mt-3 font-semibold">No collections for {{ $monthLabel }}</p>
                <p class="mt-1 text-sm text-slate-500">Add today's cash, UPI and online totals at the end of the day.</p>
                <button wire:click="create" class="btn-primary mt-5"><x-icon name="plus" class="size-4" /> Add collection</button>
            </div>
        @else
            {{-- Desktop table --}}
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                        <tr>
                            <th class="px-5 py-3">Date</th>
                            <th class="px-5 py-3 text-right">Cash</th>
                            <th class="px-5 py-3 text-right">UPI</th>
                            <th class="px-5 py-3 text-right">Online</th>
                            <th class="px-5 py-3 text-right">Total</th>
                            <th class="px-5 py-3">Note</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($entries as $e)
                            <tr wire:key="row-{{ $e->id }}" class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                <td class="px-5 py-3">
                                    <p class="font-semibold">{{ $e->date->format('d M Y') }}</p>
                                    <p class="text-xs text-slate-500">{{ $e->date->format('l') }}</p>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">{{ money($e->cash) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums">{{ money($e->upi) }}</td>
                                <td class="px-5 py-3 text-right tabular-nums">{{ money($e->online) }}</td>
                                <td class="px-5 py-3 text-right font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ money($e->total) }}</td>
                                <td class="max-w-48 truncate px-5 py-3 text-slate-500" title="{{ $e->note }}">{{ $e->note ?: '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right">
                                    <button wire:click="edit({{ $e->id }})" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">Edit</button>
                                    @can('delete-records')
                                        <button wire:click="confirmDelete({{ $e->id }})" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">Delete</button>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-slate-50 font-bold dark:bg-slate-800/50">
                        <tr>
                            <td class="px-5 py-3">Month total</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ money($totals['cash']) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ money($totals['upi']) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums">{{ money($totals['online']) }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-emerald-600 dark:text-emerald-400">{{ money($totals['total']) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {{-- Mobile cards --}}
            <ul class="divide-y divide-slate-100 md:hidden dark:divide-slate-800">
                @foreach ($entries as $e)
                    <li wire:key="card-{{ $e->id }}" class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold">{{ $e->date->format('d M Y') }} <span class="text-xs font-normal text-slate-500">· {{ $e->date->format('D') }}</span></p>
                                @if ($e->note) <p class="mt-0.5 text-xs text-slate-500">{{ $e->note }}</p> @endif
                            </div>
                            <p class="text-lg font-extrabold tabular-nums text-emerald-600 dark:text-emerald-400">{{ money($e->total) }}</p>
                        </div>
                        <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                            <div class="rounded-lg bg-emerald-50 py-1.5 dark:bg-emerald-500/10"><p class="text-slate-500">Cash</p><p class="font-semibold tabular-nums">{{ money($e->cash) }}</p></div>
                            <div class="rounded-lg bg-sky-50 py-1.5 dark:bg-sky-500/10"><p class="text-slate-500">UPI</p><p class="font-semibold tabular-nums">{{ money($e->upi) }}</p></div>
                            <div class="rounded-lg bg-amber-50 py-1.5 dark:bg-amber-500/10"><p class="text-slate-500">Online</p><p class="font-semibold tabular-nums">{{ money($e->online) }}</p></div>
                        </div>
                        <div class="mt-2 flex justify-end gap-1">
                            <button wire:click="edit({{ $e->id }})" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-brand-600 hover:bg-brand-50 dark:text-brand-400">Edit</button>
                            @can('delete-records')
                                <button wire:click="confirmDelete({{ $e->id }})" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:text-rose-400">Delete</button>
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Add / edit form --}}
    @if ($showForm)
        <x-modal :title="$editingId ? 'Edit collection' : 'Add collection'">
            <form wire:submit="save" class="space-y-4">
                <div>
                    <label class="label" for="c-date">Date</label>
                    <input id="c-date" type="date" wire:model.live="date" max="{{ today()->toDateString() }}" class="input dark:[color-scheme:dark]">
                    @error('date') <p class="error">{{ $message }}</p> @enderror
                    @if ($duplicateId && ! $editingId)
                        <div class="mt-2 flex items-center justify-between gap-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                            <span>This date already has an entry.</span>
                            <button type="button" wire:click="editDuplicate" class="font-bold underline">Edit it instead</button>
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @foreach (['cash' => ['Cash', 'emerald'], 'upi' => ['UPI', 'sky'], 'online' => ['Online', 'amber']] as $f => [$label, $tone])
                        <div>
                            <label class="label" for="c-{{ $f }}">{{ $label }}</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-400">{{ \App\Models\Setting::get('currency_symbol') }}</span>
                                <input id="c-{{ $f }}" type="number" step="0.01" min="0" inputmode="decimal" wire:model.live.debounce.250ms="{{ $f }}"
                                       @if ($f === 'cash') autofocus @endif class="input pl-7 tabular-nums" placeholder="0">
                            </div>
                            @error($f) <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>

                <div class="flex items-center justify-between rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 px-4 py-3 text-white">
                    <span class="text-sm font-medium">Day total</span>
                    <span class="text-xl font-extrabold tabular-nums">{{ money($formTotal) }}</span>
                </div>

                <div>
                    <label class="label" for="c-note">Note <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="c-note" wire:model="note" class="input" placeholder="e.g. Festival rush, closed early">
                    @error('note') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="cancel" class="btn-ghost">Cancel</button>
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save changes' : 'Add entry' }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Delete confirm --}}
    @if ($confirmingDelete)
        <x-modal title="Delete entry?" size="max-w-sm">
            <p class="text-sm text-slate-600 dark:text-slate-300">This permanently removes the collection entry. It can't be undone.</p>
            <div class="mt-5 flex justify-end gap-2">
                <button wire:click="cancel" class="btn-ghost">Cancel</button>
                <button wire:click="delete" class="btn bg-rose-600 text-white hover:bg-rose-700">Delete</button>
            </div>
        </x-modal>
    @endif
</div>
