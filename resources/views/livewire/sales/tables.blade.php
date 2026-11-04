<div class="space-y-5" wire:poll.30s>
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex gap-4 text-sm">
            <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-emerald-500"></span> Free</span>
            <span class="flex items-center gap-1.5"><span class="size-3 rounded-full bg-brand-500"></span> Running · {{ $busy }} · {{ money($running) }}</span>
        </div>
        @if ($canManage)
            <div class="ml-auto flex flex-wrap gap-2">
                <label class="btn-ghost cursor-pointer"><input type="checkbox" wire:model.live="manage" class="size-4 rounded border-slate-300 text-brand-500"> Edit tables</label>
                @if ($manage)
                    <button wire:click="$set('showBulk', true)" class="btn-ghost">Quick add</button>
                    <button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> Add table</button>
                @endif
            </div>
        @endif
    </div>

    @forelse ($areas as $area => $list)
        <section wire:key="area-{{ $area ?: 'none' }}">
            @if ($area !== '' || $areas->count() > 1)<h3 class="mb-2 text-sm font-bold text-slate-500">{{ $area ?: 'Tables' }}</h3>@endif
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6">
                @foreach ($list as $t)
                    @php $o = $t->openOrder; @endphp
                    <div wire:key="t-{{ $t->id }}" @class(['card relative flex flex-col overflow-hidden', 'opacity-50' => ! $t->is_active])>
                        <a href="{{ route('pos', ['table' => $t->id]) }}" wire:navigate @class(['flex flex-1 flex-col p-4 transition',
                            'bg-gradient-to-br from-brand-500 to-rose-500 text-white' => $o,
                            'hover:bg-emerald-50 dark:hover:bg-emerald-500/10' => ! $o])>
                            <span class="flex items-start justify-between gap-2">
                                <span class="text-2xl font-extrabold">{{ $t->name }}</span>
                                <span @class(['rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase', 'bg-white/25' => $o, 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' => ! $o])>{{ $o ? 'running' : 'free' }}</span>
                            </span>
                            @if ($o)
                                <span class="mt-2 text-xl font-extrabold tabular-nums">{{ money($o->total) }}</span>
                                <span class="text-xs text-white/85">{{ $o->items_count }} item(s){{ $o->guests ? ' · '.$o->guests.' guests' : '' }} · {{ $o->created_at->diffForHumans(null, true) }}</span>
                            @else
                                <span class="mt-2 text-xs text-slate-500">{{ $t->seats }} seats · tap to start</span>
                            @endif
                        </a>
                        @if ($o && ! $manage)
                            <div class="flex border-t border-white/20 bg-rose-600/90 text-xs font-semibold text-white">
                                <button wire:click="startMove({{ $o->id }})" class="flex-1 py-1.5 hover:bg-white/10">Move</button>
                                <a href="{{ route('orders.print', $o) }}" target="_blank" class="flex-1 py-1.5 text-center hover:bg-white/10">Print bill</a>
                            </div>
                        @endif
                        @if ($manage)
                            <div class="flex border-t border-slate-100 text-xs font-semibold dark:border-slate-800">
                                <button wire:click="edit({{ $t->id }})" class="flex-1 py-1.5 hover:bg-slate-50 dark:hover:bg-slate-800">Edit</button>
                                <button wire:click="toggle({{ $t->id }})" class="flex-1 py-1.5 hover:bg-slate-50 dark:hover:bg-slate-800">{{ $t->is_active ? 'Hide' : 'Show' }}</button>
                                @can('delete-records')
                                    <button wire:click="delete({{ $t->id }})" wire:confirm="Delete {{ $t->name }}?" class="flex-1 py-1.5 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">Delete</button>
                                @endcan
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <div class="card flex flex-col items-center gap-3 px-6 py-14 text-center">
            <span class="grid size-14 place-items-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/10"><x-icon name="grid" class="size-7" /></span>
            <h3 class="text-lg font-bold">No tables yet</h3>
            <p class="max-w-md text-sm text-slate-500">Add your tables once (T1–T10, or by floor: Ground, AC hall, Terrace). Then tap a table to open its bill.</p>
            @if ($canManage)<button wire:click="$set('showBulk', true)" class="btn-primary">Quick add tables</button>@endif
        </div>
    @endforelse

    @if ($showForm)
        <x-modal :title="$editingId ? 'Edit table' : 'Add table'" close="closeForm" size="max-w-sm">
            <form wire:submit="save" class="space-y-4">
                <div>
                    <label class="label" for="tn">Table name</label>
                    <input id="tn" wire:model="t.name" class="input" placeholder="T1" autofocus>
                    @error('t.name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="ta">Area <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="ta" wire:model="t.area" class="input" placeholder="AC hall">
                    </div>
                    <div>
                        <label class="label" for="ts">Seats</label>
                        <input id="ts" wire:model="t.seats" type="number" min="1" max="50" class="input">
                        @error('t.seats') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-2"><button type="button" wire:click="closeForm" class="btn-ghost">Cancel</button><button class="btn-primary">Save</button></div>
            </form>
        </x-modal>
    @endif

    @if ($showBulk)
        <x-modal title="Quick add tables" close="closeForm" size="max-w-sm">
            <form wire:submit="saveBulk" class="space-y-4">
                <div class="grid grid-cols-3 gap-3">
                    <div><label class="label" for="bp">Prefix</label><input id="bp" wire:model="bulk.prefix" class="input" placeholder="T"></div>
                    <div><label class="label" for="bf">From</label><input id="bf" wire:model="bulk.from" type="number" min="0" class="input"></div>
                    <div><label class="label" for="bt">To</label><input id="bt" wire:model="bulk.to" type="number" min="0" class="input"></div>
                </div>
                @error('bulk.to') <p class="error">{{ $message }}</p> @enderror
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="label" for="ba">Area</label><input id="ba" wire:model="bulk.area" class="input" placeholder="optional"></div>
                    <div><label class="label" for="bs">Seats each</label><input id="bs" wire:model="bulk.seats" type="number" min="1" class="input"></div>
                </div>
                <p class="text-xs text-slate-500">Creates {{ $bulk['prefix'] }}{{ $bulk['from'] }} … {{ $bulk['prefix'] }}{{ $bulk['to'] }}. Names that already exist are skipped.</p>
                <div class="flex justify-end gap-2"><button type="button" wire:click="closeForm" class="btn-ghost">Cancel</button><button class="btn-primary">Add tables</button></div>
            </form>
        </x-modal>
    @endif

    @if ($moving)
        <x-modal title="Move bill to another table" close="closeMove" size="max-w-sm">
            <select wire:model="moveTo" class="input">
                <option value="">Choose a free table…</option>
                @foreach ($free as $f)<option value="{{ $f->id }}">{{ $f->label() }}</option>@endforeach
            </select>
            @error('table_id') <p class="error">{{ $message }}</p> @enderror
            <div class="mt-4 flex justify-end gap-2"><button wire:click="closeMove" class="btn-ghost">Cancel</button><button wire:click="move" class="btn-primary">Move</button></div>
        </x-modal>
    @endif
</div>
