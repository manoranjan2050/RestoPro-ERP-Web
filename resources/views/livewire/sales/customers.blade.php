<div class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
        <div class="relative min-w-0 flex-1 sm:max-w-sm">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><x-icon name="search" class="size-4" /></span>
            <input wire:model.live.debounce.300ms="search" type="search" class="input pl-9" placeholder="Search name or mobile">
        </div>
        <span class="text-sm text-slate-500">{{ number_format($total) }} customers</span>
        <button wire:click="create" class="btn-primary ml-auto"><x-icon name="plus" class="size-4" /> Add customer</button>
    </div>

    @if ($upcoming)
        <div class="card p-4">
            <h3 class="text-sm font-bold">Coming up this week</h3>
            <div class="mt-3 flex gap-3 overflow-x-auto pb-1">
                @foreach ($upcoming as $u)
                    @php $msg = ($u['label'] === '🎂 Birthday' ? 'Happy Birthday' : 'Happy Anniversary').', '.$u['customer']->name.'! 🎉 Best wishes from '.\App\Models\Setting::get('business_name').'.'; @endphp
                    <div wire:key="up-{{ $u['customer']->id }}-{{ $loop->index }}" class="flex shrink-0 items-center gap-3 rounded-xl bg-gradient-to-r from-pink-50 to-amber-50 px-3 py-2 dark:from-pink-500/10 dark:to-amber-500/10">
                        <div>
                            <p class="text-sm font-semibold">{{ $u['customer']->name }}</p>
                            <p class="text-xs text-slate-500">{{ $u['label'] }} · {{ $u['days'] === 0 ? 'today' : ($u['days'] === 1 ? 'tomorrow' : $u['date']->format('D d M')) }}</p>
                        </div>
                        @if ($url = $u['customer']->whatsappUrl($msg))
                            <a href="{{ $url }}" target="_blank" rel="noopener" class="rounded-lg bg-emerald-500 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-600">Wish</a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card overflow-hidden">
        @forelse ($customers as $x)
            <div wire:key="cust-{{ $x->id }}" class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-gradient-to-br from-sky-400 to-indigo-500 text-sm font-bold text-white">{{ $x->initials() }}</span>
                <div class="min-w-0 flex-1">
                    <button wire:click="edit({{ $x->id }})" class="text-left text-sm font-semibold hover:text-brand-600">{{ $x->name }}</button>
                    <p class="truncate text-xs text-slate-500">
                        {{ $x->mobile ?: 'no mobile' }}{{ $x->address ? ' · '.$x->address : '' }}{{ $x->gstin ? ' · GSTIN '.$x->gstin : '' }}
                    </p>
                </div>
                @if ($x->mobile)
                    <a href="tel:{{ $x->mobile }}" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Call</a>
                    <a href="{{ $x->whatsappUrl() }}" target="_blank" rel="noopener" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-500/10">WhatsApp</a>
                @endif
                <button wire:click="edit({{ $x->id }})" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">Edit</button>
                @can('delete-records')
                    <button wire:click="confirmDelete({{ $x->id }})" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">Delete</button>
                @endcan
            </div>
        @empty
            <div class="flex flex-col items-center gap-2 px-6 py-14 text-center">
                <span class="grid size-14 place-items-center rounded-2xl bg-sky-50 text-sky-500 dark:bg-sky-500/10"><x-icon name="id" class="size-7" /></span>
                <h3 class="text-lg font-bold">{{ $total ? 'No customer matches' : 'No customers yet' }}</h3>
                <p class="max-w-md text-sm text-slate-500">Customers are also added from the POS when you type a mobile number on a bill.</p>
            </div>
        @endforelse
    </div>
    <div>{{ $customers->links() }}</div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Edit customer' : 'Add customer'">
            <form wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="c-name">Name</label>
                        <input id="c-name" wire:model="c.name" class="input" autofocus>
                        @error('c.name') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="c-mob">Mobile</label>
                        <input id="c-mob" wire:model="c.mobile" class="input" inputmode="numeric" placeholder="10-digit mobile">
                        @error('c.mobile') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="c-addr">Address <span class="font-normal text-slate-400">(for delivery)</span></label>
                        <input id="c-addr" wire:model="c.address" class="input" maxlength="255">
                    </div>
                    <div>
                        <label class="label" for="c-bd">Birthday</label>
                        <input id="c-bd" wire:model="c.birthday" type="date" class="input">
                        @error('c.birthday') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="c-an">Anniversary</label>
                        <input id="c-an" wire:model="c.anniversary" type="date" class="input">
                        @error('c.anniversary') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="c-em">Email <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="c-em" wire:model="c.email" type="email" class="input">
                        @error('c.email') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="c-gst">GSTIN <span class="font-normal text-slate-400">(business customers)</span></label>
                        <input id="c-gst" wire:model="c.gstin" class="input uppercase" maxlength="15">
                        @error('c.gstin') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="c-note">Note</label>
                        <input id="c-note" wire:model="c.note" class="input" maxlength="255" placeholder="likes less sugar, regular on Sundays…">
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="cancel" class="btn-ghost">Cancel</button>
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save' : 'Add customer' }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($confirmingDelete)
        <x-modal title="Delete customer?" size="max-w-sm">
            <p class="text-sm text-slate-600 dark:text-slate-300">Their contact details are removed. Old bills keep the name printed on them.</p>
            <div class="mt-5 flex justify-end gap-2">
                <button wire:click="cancel" class="btn-ghost">Cancel</button>
                <button wire:click="delete" class="btn bg-rose-600 text-white hover:bg-rose-700">Delete</button>
            </div>
        </x-modal>
    @endif
</div>
