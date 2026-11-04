<div>
    @if ($open)
        <x-modal title="Expense categories" close="close">
            <form wire:submit="add" class="flex gap-2">
                <input wire:model="newName" class="input" placeholder="New category, e.g. Oil" autofocus>
                <button class="btn-primary shrink-0"><x-icon name="plus" class="size-4" /> Add</button>
            </form>
            @error('newName') <p class="error">{{ $message }}</p> @enderror
            @error('delete') <p class="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ $message }}</p> @enderror

            <ul class="mt-4 divide-y divide-slate-100 rounded-2xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
                @foreach ($categories as $c)
                    <li wire:key="cat-{{ $c->id }}" class="px-3 py-2.5">
                        @if ($editingId === $c->id)
                            <form wire:submit="saveEdit" class="space-y-2">
                                <input wire:model="editName" class="input" autofocus>
                                @error('editName') <p class="error">{{ $message }}</p> @enderror
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @foreach ($palette as $p)
                                        <button type="button" wire:click="$set('editColor', '{{ $p }}')" class="size-6 rounded-full ring-offset-2 dark:ring-offset-slate-900 {{ strtolower($editColor) === $p ? 'ring-2 ring-slate-800 dark:ring-white' : '' }}" style="background: {{ $p }}" aria-label="Colour {{ $p }}"></button>
                                    @endforeach
                                </div>
                                <div class="flex justify-end gap-2">
                                    <button type="button" wire:click="$set('editingId', null)" class="btn-ghost py-1.5">Cancel</button>
                                    <button class="btn-primary py-1.5">Save</button>
                                </div>
                            </form>
                        @else
                            <div class="flex items-center gap-3">
                                <span class="size-3 shrink-0 rounded-full" style="background: {{ $c->color }}"></span>
                                <div class="min-w-0 flex-1">
                                    <p @class(['truncate text-sm font-semibold', 'text-slate-400 line-through' => ! $c->is_active])>{{ $c->name }}</p>
                                    <p class="text-xs text-slate-500">{{ $c->expenses_count }} expense(s){{ $c->is_active ? '' : ' · hidden' }}</p>
                                </div>
                                <button wire:click="startEdit({{ $c->id }})" class="rounded-lg px-2 py-1 text-xs font-semibold text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">Edit</button>
                                <button wire:click="toggle({{ $c->id }})" class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">{{ $c->is_active ? 'Hide' : 'Show' }}</button>
                                @can('delete-records')
                                    @if ($c->expenses_count === 0)
                                        <button wire:click="delete({{ $c->id }})" wire:confirm="Delete “{{ $c->name }}”?" class="rounded-lg px-2 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">Delete</button>
                                    @endif
                                @endcan
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-slate-500">Hidden categories stay on old expenses but don't appear when adding new ones.</p>
        </x-modal>
    @endif
</div>
