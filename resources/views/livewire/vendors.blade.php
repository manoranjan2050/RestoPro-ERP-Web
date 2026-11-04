<div class="space-y-6">
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">
        <x-stat label="Total owed to vendors" :value="money($totalDue)" icon="truck" tone="rose" :hint="$withDue.' vendor(s) with dues'" class="col-span-2 lg:col-span-1" />
        <x-stat label="Active vendors" :value="$activeCount" icon="users" tone="sky" hint="Suppliers you buy from" />
        <x-stat label="Vendors with dues" :value="$withDue" icon="receipt" tone="amber" hint="Tap Pay on a vendor to settle" />
    </div>

    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4 dark:border-slate-800">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name or mobile…" class="input w-full sm:w-64">
            <div class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1 dark:border-slate-700 dark:bg-slate-800">
                @foreach (['active' => 'Active', 'due' => 'With dues', 'all' => 'All'] as $k => $label)
                    <button wire:click="$set('show', '{{ $k }}')" @class([
                        'rounded-lg px-3 py-1 text-sm font-semibold',
                        'bg-white text-brand-600 shadow-sm dark:bg-slate-900 dark:text-brand-400' => $show === $k,
                        'text-slate-500' => $show !== $k,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
            <button wire:click="create" class="btn-primary ml-auto"><x-icon name="plus" class="size-4" /> Add vendor</button>
        </div>

        @if ($vendors->isEmpty())
            <div class="px-5 py-14 text-center">
                <div class="mx-auto grid size-14 place-items-center rounded-2xl bg-sky-50 text-sky-500 dark:bg-sky-500/10"><x-icon name="truck" class="size-7" /></div>
                <p class="mt-3 font-semibold">No vendors here yet</p>
                <p class="mt-1 text-sm text-slate-500">Add your milk, vegetable, gas and grocery suppliers to track what you owe them.</p>
            </div>
        @else
            <ul class="grid gap-px bg-slate-100 sm:grid-cols-2 xl:grid-cols-3 dark:bg-slate-800">
                @foreach ($vendors as $v)
                    @php $due = $v->due(); @endphp
                    <li wire:key="v-{{ $v->id }}" class="flex flex-col bg-white p-4 dark:bg-slate-900">
                        <div class="flex items-start gap-3">
                            <span class="grid size-11 shrink-0 place-items-center rounded-xl text-base font-bold text-white"
                                  style="background: {{ $v->defaultCategory->color ?? '#64748b' }}">{{ mb_strtoupper(mb_substr($v->name, 0, 1)) }}</span>
                            <div class="min-w-0 flex-1">
                                <p @class(['truncate font-semibold', 'text-slate-400' => ! $v->is_active])><a href="{{ route('vendors.khata', $v) }}" wire:navigate class="hover:underline">{{ $v->name }}</a>
                                    @unless ($v->is_active) <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-500 dark:bg-slate-800">Inactive</span> @endunless
                                </p>
                                <p class="truncate text-xs text-slate-500">
                                    {{ $v->defaultCategory->name ?? 'No usual category' }}
                                    @if ($v->mobile) · <a href="tel:{{ $v->mobile }}" class="hover:underline">{{ $v->mobile }}</a> @endif
                                </p>
                            </div>
                            <div class="text-right">
                                <p @class(['font-extrabold tabular-nums', 'text-rose-600 dark:text-rose-400' => $due > 0, 'text-emerald-600 dark:text-emerald-400' => $due <= 0])>{{ money(abs($due)) }}</p>
                                <p class="text-[11px] text-slate-500">{{ $due > 0 ? 'due' : ($due < 0 ? 'advance' : 'settled') }}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-2 text-xs text-slate-500">
                            <span class="min-w-0 truncate">{{ $v->expenses_count }} bill(s) · {{ money($v->total_bills) }}{{ $v->last_bill ? ' · last '.\Illuminate\Support\Carbon::parse($v->last_bill)->format('d M') : '' }}</span>
                            <span class="flex shrink-0 gap-1">
                                @if ($due > 0)
                                    <a href="{{ route('vendors.khata', ['vendor' => $v, 'pay' => 1]) }}" wire:navigate class="rounded-lg bg-emerald-50 px-2 py-1 font-semibold text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-400">Pay</a>
                                @endif
                                <a href="{{ route('vendors.khata', $v) }}" wire:navigate class="rounded-lg px-2 py-1 font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Khata</a>
                                <button wire:click="edit({{ $v->id }})" class="rounded-lg px-2 py-1 font-semibold text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">Edit</button>
                                @can('delete-records')
                                    <button wire:click="confirmDelete({{ $v->id }})" class="rounded-lg px-2 py-1 font-semibold text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">Delete</button>
                                @endcan
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Edit vendor' : 'Add vendor'">
            <form wire:submit="save" class="space-y-4">
                <div>
                    <label class="label" for="v-name">Vendor name</label>
                    <input id="v-name" wire:model="name" class="input" autofocus placeholder="e.g. Ramesh Milk Supply">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="v-mobile">Mobile</label>
                        <input id="v-mobile" wire:model="mobile" inputmode="numeric" class="input" placeholder="10 digits">
                        @error('mobile') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="v-cat">Usual category</label>
                        <select id="v-cat" wire:model="default_category_id" class="input">
                            <option value="">—</option>
                            @foreach ($categories as $c) <option value="{{ $c->id }}">{{ $c->name }}</option> @endforeach
                        </select>
                        @error('default_category_id') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="label" for="v-ob">Opening balance owed <span class="font-normal text-slate-400">(before using this app)</span></label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-400">{{ \App\Models\Setting::get('currency_symbol') }}</span>
                        <input id="v-ob" type="number" step="0.01" min="0" wire:model="opening_balance" class="input pl-7" placeholder="0">
                    </div>
                    @error('opening_balance') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="v-addr">Address <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="v-addr" wire:model="address" class="input">
                    @error('address') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="v-note">Note <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="v-note" wire:model="note" class="input" placeholder="e.g. Delivers 6 AM, pays weekly">
                    @error('note') <p class="error">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-brand-500 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                    Active (shows when adding expenses)
                </label>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="cancel" class="btn-ghost">Cancel</button>
                    <button class="btn-primary">{{ $editingId ? 'Save changes' : 'Add vendor' }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($confirmingDelete)
        <x-modal title="Delete vendor?" size="max-w-sm">
            <p class="text-sm text-slate-600 dark:text-slate-300">A vendor with no bills is deleted. A vendor who already has bills is deactivated instead, so your history stays intact.</p>
            <div class="mt-5 flex justify-end gap-2">
                <button wire:click="cancel" class="btn-ghost">Cancel</button>
                <button wire:click="delete" class="btn bg-rose-600 text-white hover:bg-rose-700">Delete</button>
            </div>
        </x-modal>
    @endif
</div>
