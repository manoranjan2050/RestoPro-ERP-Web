@php
    $statusBadge = [
        'paid' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
        'partial' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
        'unpaid' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400',
    ];
    $catTotal = max(array_sum(array_column($byCategory, 'amount')), 1);
@endphp
<div class="space-y-6">
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <button wire:click="shiftMonth(-1)" class="rounded-lg px-2.5 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Previous month">‹</button>
            <input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}"
                   class="w-40 border-0 bg-transparent py-1 text-center text-sm font-semibold focus:ring-0 dark:[color-scheme:dark]">
            <button wire:click="shiftMonth(1)" @disabled($isCurrentMonth) class="rounded-lg px-2.5 py-1.5 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" aria-label="Next month">›</button>
        </div>
        <div class="ml-auto flex gap-2">
            <button wire:click="$dispatch('open-categories')" class="btn-ghost"><x-icon name="cog" class="size-4" /> Categories</button>
            <button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> Add expense</button>
        </div>
    </div>

    {{-- KPIs --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-stat label="Month expenses" :value="money($monthTotals['amount'])" icon="receipt" tone="rose" :hint="$monthTotals['count'].' bill(s) · '.$monthLabel" />
        <x-stat label="Paid" :value="money($monthTotals['paid'])" icon="check" tone="emerald" hint="Settled this month's bills" />
        <x-stat label="Unpaid (this month)" :value="money($monthTotals['unpaid'])" icon="lock" tone="amber" hint="Still owed to vendors" />
        <x-stat label="Total dues (all time)" :value="money($allDue)" icon="truck" tone="indigo" hint="Unpaid bills across every month" />
    </div>

    {{-- Category breakdown --}}
    @if (count($byCategory))
        <div class="card p-5">
            <div class="flex items-center justify-between">
                <h3 class="font-bold">Where the money went</h3>
                <span class="text-xs text-slate-500">Tap a category to filter</span>
            </div>
            <div class="mt-4 flex h-3 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                @foreach ($byCategory as $c)
                    <div style="width: {{ $c['amount'] / $catTotal * 100 }}%; background: {{ $c['color'] }}" title="{{ $c['name'] }}"></div>
                @endforeach
            </div>
            <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($byCategory as $c)
                    <button wire:click="$set('filterCategory', '{{ $filterCategory == $c['id'] ? '' : $c['id'] }}')"
                            @class(['flex items-center gap-2 rounded-xl border px-3 py-2 text-left transition',
                                    'border-brand-400 bg-brand-50 dark:bg-brand-500/10' => $filterCategory == $c['id'],
                                    'border-slate-200 hover:border-slate-300 dark:border-slate-800 dark:hover:border-slate-700' => $filterCategory != $c['id']])>
                        <span class="size-2.5 shrink-0 rounded-full" style="background: {{ $c['color'] }}"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-xs font-medium text-slate-500 dark:text-slate-400">{{ $c['name'] }}</span>
                            <span class="block text-sm font-bold tabular-nums">{{ money($c['amount']) }} <span class="text-[11px] font-medium text-slate-400">{{ round($c['amount'] / $catTotal * 100) }}%</span></span>
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Filters + list --}}
    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4 dark:border-slate-800">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search note, vendor, category…" class="input w-full sm:w-56">
            <select wire:model.live="filterCategory" class="input w-[calc(50%-0.25rem)] sm:w-44">
                <option value="">All categories</option>
                @foreach ($allCategories as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
            </select>
            <select wire:model.live="filterVendor" class="input w-[calc(50%-0.25rem)] sm:w-44">
                <option value="">All vendors</option>
                <option value="none">No vendor</option>
                @foreach ($vendors as $v) <option value="{{ $v->id }}">{{ $v->name }}</option> @endforeach
            </select>
            <div class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1 dark:border-slate-700 dark:bg-slate-800">
                @foreach (['' => 'All', 'paid' => 'Paid', 'due' => 'Unpaid'] as $k => $label)
                    <button wire:click="$set('filterStatus', '{{ $k }}')" @class([
                        'rounded-lg px-3 py-1 text-sm font-semibold',
                        'bg-white text-brand-600 shadow-sm dark:bg-slate-900 dark:text-brand-400' => $filterStatus === $k,
                        'text-slate-500' => $filterStatus !== $k,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
            @if ($filtersOn)
                <button wire:click="clearFilters" class="text-sm font-semibold text-brand-600 hover:underline dark:text-brand-400">Clear</button>
                <span class="ml-auto text-sm text-slate-500">{{ $filteredTotals['count'] }} bill(s) · <b class="text-slate-800 dark:text-slate-200">{{ money($filteredTotals['amount']) }}</b></span>
            @endif
        </div>

        @if ($entries->isEmpty())
            <div class="px-5 py-14 text-center">
                <div class="mx-auto grid size-14 place-items-center rounded-2xl bg-rose-50 text-rose-500 dark:bg-rose-500/10"><x-icon name="receipt" class="size-7" /></div>
                <p class="mt-3 font-semibold">{{ $filtersOn ? 'No expenses match these filters' : 'No expenses for '.$monthLabel }}</p>
                <p class="mt-1 text-sm text-slate-500">Record milk, vegetables, gas, electricity and every other bill here.</p>
                @unless ($filtersOn) <button wire:click="create" class="btn-primary mt-5"><x-icon name="plus" class="size-4" /> Add expense</button> @endunless
            </div>
        @else
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($entries as $e)
                    <li wire:key="exp-{{ $e->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 hover:bg-slate-50/70 sm:px-5 dark:hover:bg-slate-800/40">
                        <div class="grid size-11 shrink-0 place-items-center rounded-xl text-center leading-none" style="background: {{ $e->category->color }}1f; color: {{ $e->category->color }}">
                            <span class="text-sm font-bold">{{ $e->date->format('d') }}<br><span class="text-[10px] font-semibold uppercase">{{ $e->date->format('M') }}</span></span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                {{ $e->category->name }}
                                @if ($e->vendor) <span class="font-normal text-slate-500">· {{ $e->vendor->name }}</span> @endif
                                @if ($e->bill_path)
                                    <a href="{{ route('expenses.bill', $e) }}" target="_blank" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300" title="View bill"><x-icon name="camera" class="size-3" /> Bill</a>
                                @endif
                            </p>
                            <p class="truncate text-xs text-slate-500">
                                {{ $e->note ?: $e->date->format('l') }}
                                @if ($e->status !== 'unpaid') · {{ \App\Models\Expense::MODES[$e->payment_mode] ?? '' }} @endif
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-bold tabular-nums">{{ money($e->amount) }}</p>
                            <span class="inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ $statusBadge[$e->status] }}">
                                {{ $e->status === 'partial' ? 'Due '.money($e->balance()) : $e->status }}
                            </span>
                        </div>
                        <div class="flex w-full justify-end gap-1 sm:w-auto">
                            @if ($e->status !== 'paid')
                                <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                                    <button @click="open = !open" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10">Mark paid ▾</button>
                                    <div x-cloak x-show="open" x-transition class="absolute right-0 z-20 mt-1 w-32 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lg dark:border-slate-700 dark:bg-slate-800">
                                        @foreach (\App\Models\Expense::MODES as $k => $label)
                                            <button wire:click="markPaid({{ $e->id }}, '{{ $k }}')" @click="open = false" class="block w-full px-3 py-1.5 text-left text-sm hover:bg-slate-50 dark:hover:bg-slate-700">via {{ $label }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            <button wire:click="edit({{ $e->id }})" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">Edit</button>
                            @can('delete-records')
                                <button wire:click="confirmDelete({{ $e->id }})" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">Delete</button>
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="flex justify-between border-t border-slate-100 bg-slate-50 px-5 py-3 text-sm font-bold dark:border-slate-800 dark:bg-slate-800/50">
                <span>{{ $filtersOn ? 'Filtered total' : 'Month total' }}</span>
                <span class="tabular-nums">{{ money($filteredTotals['amount']) }} @if ($filteredTotals['unpaid'] > 0) <span class="font-medium text-rose-600 dark:text-rose-400">· {{ money($filteredTotals['unpaid']) }} unpaid</span> @endif</span>
            </div>
        @endif
    </div>

    {{-- Add / edit form --}}
    @if ($showForm)
        <x-modal :title="$editingId ? 'Edit expense' : 'Add expense'">
            <form wire:submit="save" class="space-y-4" x-data @focus-amount.window="$nextTick(() => document.getElementById('e-amount')?.focus())">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="e-date">Date</label>
                        <input id="e-date" type="date" wire:model="date" max="{{ today()->toDateString() }}" class="input dark:[color-scheme:dark]">
                        @error('date') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="e-amount">Amount</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-400">{{ \App\Models\Setting::get('currency_symbol') }}</span>
                            <input id="e-amount" type="number" step="0.01" min="0" inputmode="decimal" wire:model="amount" autofocus class="input pl-7 tabular-nums" placeholder="0">
                        </div>
                        @error('amount') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="label" for="e-cat">Category</label>
                    <select id="e-cat" wire:model.live="category_id" class="input">
                        <option value="">Choose…</option>
                        @foreach ($allCategories as $c)
                            @if ($c->is_active || (string) $c->id === $category_id) <option value="{{ $c->id }}">{{ $c->name }}</option> @endif
                        @endforeach
                        <option value="__new">+ Add new category…</option>
                    </select>
                    @error('category_id') <p class="error">{{ $message }}</p> @enderror
                    @if ($category_id === '__new')
                        <input wire:model="new_category" class="input mt-2" placeholder="New category name, e.g. Oil">
                        @error('new_category') <p class="error">{{ $message }}</p> @enderror
                    @endif
                </div>

                <div>
                    <label class="label" for="e-vendor">Vendor <span class="font-normal text-slate-400">(optional)</span></label>
                    <select id="e-vendor" wire:model.live="vendor_id" class="input">
                        <option value="">No vendor</option>
                        @foreach ($vendors as $v)
                            @if ($v->is_active || (string) $v->id === $vendor_id) <option value="{{ $v->id }}">{{ $v->name }}</option> @endif
                        @endforeach
                        <option value="__new">+ Add new vendor…</option>
                    </select>
                    @error('vendor_id') <p class="error">{{ $message }}</p> @enderror
                    @if ($vendor_id === '__new')
                        <input wire:model="new_vendor" class="input mt-2" placeholder="Vendor name, e.g. Ramesh Milk Supply">
                        @error('new_vendor') <p class="error">{{ $message }}</p> @enderror
                    @endif
                </div>

                <div>
                    <span class="label">Payment</span>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (['paid' => 'Paid', 'unpaid' => 'Unpaid (udhaar)'] as $k => $label)
                            <button type="button" wire:click="$set('status', '{{ $k }}')" @class([
                                'rounded-xl border px-3 py-2.5 text-sm font-semibold transition',
                                'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $status === $k && $k === 'paid',
                                'border-rose-500 bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400' => $status === $k && $k === 'unpaid',
                                'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => $status !== $k,
                            ])>{{ $label }}</button>
                        @endforeach
                    </div>
                    @if ($status === 'partial')
                        <p class="mt-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">Part of this bill was paid at purchase. Saving keeps that; choose Paid or Unpaid to change it.</p>
                    @endif
                    @if ($khataPaid > 0)
                        <p class="mt-2 rounded-xl bg-sky-50 px-3 py-2 text-xs text-sky-800 dark:bg-sky-500/10 dark:text-sky-300">{{ money($khataPaid) }} of this bill is covered by payments in the vendor's khata. Those are managed on the vendor page.</p>
                    @endif
                    @if ($status !== 'unpaid')
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach (\App\Models\Expense::MODES as $k => $label)
                                <button type="button" wire:click="$set('payment_mode', '{{ $k }}')" @class([
                                    'rounded-full px-3 py-1 text-xs font-semibold ring-1 transition',
                                    'bg-brand-500 text-white ring-brand-500' => $payment_mode === $k,
                                    'text-slate-600 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800' => $payment_mode !== $k,
                                ])>{{ $label }}</button>
                            @endforeach
                        </div>
                        @error('payment_mode') <p class="error">{{ $message }}</p> @enderror
                    @endif
                </div>

                <div>
                    <label class="label" for="e-note">Note <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="e-note" wire:model="note" class="input" placeholder="e.g. 20 L milk, 2 cylinders">
                    @error('note') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <span class="label">Bill photo <span class="font-normal text-slate-400">(optional)</span></span>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="btn-ghost cursor-pointer py-2">
                            <x-icon name="camera" class="size-4" /> {{ $bill || $existingBill ? 'Replace' : 'Attach' }}
                            <input type="file" wire:model="bill" accept="image/*,application/pdf" capture="environment" class="sr-only">
                        </label>
                        <span wire:loading wire:target="bill" class="text-sm text-slate-500">Uploading…</span>
                        @if ($bill)
                            <span class="text-sm text-emerald-600 dark:text-emerald-400">✓ {{ $bill->getClientOriginalName() }}</span>
                        @elseif ($existingBill && ! $removeBill)
                            <a href="{{ route('expenses.bill', $editingId) }}" target="_blank" class="text-sm font-semibold text-brand-600 underline dark:text-brand-400">View current</a>
                            <button type="button" wire:click="$set('removeBill', true)" class="text-sm text-rose-600 hover:underline">Remove</button>
                        @elseif ($removeBill)
                            <span class="text-sm text-slate-500">Bill will be removed</span>
                        @endif
                    </div>
                    @error('bill') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-wrap justify-end gap-2 pt-2">
                    <button type="button" wire:click="cancel" class="btn-ghost">Cancel</button>
                    @unless ($editingId)
                        <button type="button" wire:click="save(true)" class="btn-ghost" wire:loading.attr="disabled" wire:target="save,bill">Save &amp; add another</button>
                    @endunless
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="save,bill">{{ $editingId ? 'Save changes' : 'Add expense' }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($confirmingDelete)
        <x-modal title="Delete expense?" size="max-w-sm">
            <p class="text-sm text-slate-600 dark:text-slate-300">This permanently removes the expense and its bill photo. It can't be undone.</p>
            <div class="mt-5 flex justify-end gap-2">
                <button wire:click="cancel" class="btn-ghost">Cancel</button>
                <button wire:click="delete" class="btn bg-rose-600 text-white hover:bg-rose-700">Delete</button>
            </div>
        </x-modal>
    @endif

    <livewire:expense-categories />
</div>
