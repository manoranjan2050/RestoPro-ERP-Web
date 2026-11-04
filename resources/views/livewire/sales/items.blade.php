@php
    $foodDot = ['veg' => 'border-emerald-600 after:bg-emerald-600', 'egg' => 'border-amber-500 after:bg-amber-500', 'nonveg' => 'border-rose-600 after:bg-rose-600'];
@endphp
<div class="space-y-5">
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="relative min-w-0 flex-1 sm:max-w-sm">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><x-icon name="search" class="size-4" /></span>
            <input wire:model.live.debounce.300ms="search" type="search" class="input pl-9" placeholder="Search name, code or barcode">
        </div>
        <select wire:model.live="show" class="input w-auto">
            <option value="active">On the POS ({{ $counts['active'] }})</option>
            <option value="hidden">Hidden ({{ $counts['hidden'] }})</option>
            <option value="all">All ({{ $counts['all'] }})</option>
        </select>
        <div class="ml-auto flex flex-wrap gap-2">
            <button wire:click="$set('showCategories', true)" class="btn-ghost"><x-icon name="grid" class="size-4" /> Categories</button>
            <button wire:click="openImport" class="btn-ghost"><x-icon name="upload" class="size-4" /> Import</button>
            <button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> Add item</button>
        </div>
    </div>

    {{-- Category chips --}}
    <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
        <button wire:click="$set('category', '')" @class(['shrink-0 rounded-full px-3.5 py-1.5 text-sm font-semibold transition',
            'bg-slate-900 text-white dark:bg-white dark:text-slate-900' => $category === '',
            'bg-white text-slate-600 ring-1 ring-slate-200 hover:ring-slate-300 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800' => $category !== ''])>All</button>
        @foreach ($categories as $c)
            <button wire:key="chip-{{ $c->id }}" wire:click="$set('category', '{{ $c->id }}')" @class(['flex shrink-0 items-center gap-2 rounded-full px-3.5 py-1.5 text-sm font-semibold transition',
                'bg-slate-900 text-white dark:bg-white dark:text-slate-900' => $category === (string) $c->id,
                'bg-white text-slate-600 ring-1 ring-slate-200 hover:ring-slate-300 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800' => $category !== (string) $c->id,
                'opacity-50' => ! $c->is_active])>
                <span class="size-2.5 rounded-full" style="background: {{ $c->color }}"></span>{{ $c->name }}
                <span class="text-xs font-medium opacity-60">{{ $c->items_count }}</span>
            </button>
        @endforeach
    </div>

    {{-- Items --}}
    @if ($items->isEmpty())
        <div class="card flex flex-col items-center gap-3 px-6 py-14 text-center">
            <span class="grid size-14 place-items-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/10"><x-icon name="tag" class="size-7" /></span>
            <h3 class="text-lg font-bold">{{ $counts['all'] ? 'No items match' : 'Add your menu' }}</h3>
            <p class="max-w-md text-sm text-slate-500">{{ $counts['all'] ? 'Try another search or category.' : 'Add items one by one, or import your whole menu from Excel. Cakes can have sizes like 500 g / 1 kg / 2 kg, each with its own price.' }}</p>
            @unless ($counts['all'])
                <div class="flex flex-wrap justify-center gap-2">
                    <button wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> Add item</button>
                    <button wire:click="openImport" class="btn-ghost"><x-icon name="upload" class="size-4" /> Import from Excel</button>
                </div>
            @endunless
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
            @foreach ($items as $item)
                <div wire:key="item-{{ $item->id }}" @class(['card group flex gap-3 p-3 transition hover:shadow-md', 'opacity-60' => ! $item->is_active])>
                    <button wire:click="edit({{ $item->id }})" class="relative size-20 shrink-0 overflow-hidden rounded-xl bg-slate-100 dark:bg-slate-800" aria-label="Edit {{ $item->name }}">
                        @if ($item->photoUrl())
                            <img src="{{ $item->photoUrl() }}" alt="" class="size-full object-cover" loading="lazy">
                        @else
                            <span class="grid size-full place-items-center text-2xl font-extrabold text-white" style="background: {{ $item->category?->color ?? '#94a3b8' }}">{{ mb_strtoupper(mb_substr($item->name, 0, 1)) }}</span>
                        @endif
                    </button>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start gap-1.5">
                            @if ($item->food_type)
                                <span title="{{ \App\Models\Item::FOOD_TYPES[$item->food_type] }}" class="relative mt-1 size-3.5 shrink-0 rounded-sm border-2 after:absolute after:inset-[2px] after:rounded-full {{ $foodDot[$item->food_type] }}"></span>
                            @endif
                            <button wire:click="edit({{ $item->id }})" class="min-w-0 text-left font-semibold leading-snug hover:text-brand-600">{{ $item->name }}</button>
                        </div>
                        <p class="mt-0.5 truncate text-xs text-slate-500">
                            {{ $item->category?->name ?? 'No category' }}{{ $item->code ? ' · '.$item->code : '' }}{{ $gst ? ' · GST '.(int) $item->gst_rate.'%' : '' }}
                        </p>
                        <p class="mt-1 font-bold text-brand-600 dark:text-brand-400">{{ $item->priceLabel() }}</p>
                        @if ($item->variants->isNotEmpty())
                            <p class="truncate text-xs text-slate-500">{{ $item->variants->where('is_active', true)->pluck('name')->implode(' · ') }}</p>
                        @endif
                        @if ($stock && $item->track_stock)
                            <p @class(['mt-0.5 text-xs font-semibold', 'text-rose-600' => $item->isLowStock(), 'text-slate-500' => ! $item->isLowStock()])>
                                Stock {{ rtrim(rtrim(number_format($item->stockOnHand(), 3), '0'), '.') }} {{ $item->unit }}{{ $item->isLowStock() ? ' · low' : '' }}
                            </p>
                        @endif
                    </div>
                    <div class="flex flex-col items-end justify-between">
                        <button wire:click="toggle({{ $item->id }})" title="{{ $item->is_active ? 'Hide from POS' : 'Show on POS' }}"
                                @class(['relative h-5 w-9 rounded-full transition', 'bg-emerald-500' => $item->is_active, 'bg-slate-300 dark:bg-slate-700' => ! $item->is_active])>
                            <span @class(['absolute top-0.5 size-4 rounded-full bg-white shadow transition-all', 'left-[18px]' => $item->is_active, 'left-0.5' => ! $item->is_active])></span>
                        </button>
                        @can('delete-records')
                            <button wire:click="confirmDelete({{ $item->id }})" class="rounded-lg p-1 text-slate-400 opacity-0 transition hover:bg-rose-50 hover:text-rose-600 group-hover:opacity-100 focus:opacity-100 dark:hover:bg-rose-500/10" aria-label="Delete"><x-icon name="trash" class="size-4" /></button>
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>
        <div>{{ $items->links() }}</div>
    @endif

    {{-- Item form --}}
    @if ($showForm)
        <x-modal :title="$editingId ? 'Edit item' : 'Add item'" size="max-w-2xl">
            <form wire:submit="save" class="space-y-5">
                <div class="flex flex-col gap-4 sm:flex-row">
                    <div class="flex shrink-0 flex-col items-center gap-2">
                        <label class="relative grid size-28 cursor-pointer place-items-center overflow-hidden rounded-2xl bg-slate-100 ring-1 ring-slate-200 hover:ring-brand-400 dark:bg-slate-800 dark:ring-slate-700">
                            @if ($photo && method_exists($photo, 'isPreviewable') && $photo->isPreviewable())
                                <img src="{{ $photo->temporaryUrl() }}" class="size-full object-cover" alt="">
                            @elseif ($editingId && ($p = \App\Models\Item::find($editingId)?->photoUrl()))
                                <img src="{{ $p }}" class="size-full object-cover" alt="">
                            @else
                                <span class="flex flex-col items-center gap-1 text-xs text-slate-500"><x-icon name="camera" class="size-6" /> Photo</span>
                            @endif
                            <input type="file" wire:model="photo" accept="image/*" class="sr-only">
                        </label>
                        <span wire:loading wire:target="photo" class="text-xs text-slate-500">Uploading…</span>
                        @if ($editingId && \App\Models\Item::find($editingId)?->photo_path && ! $photo)
                            <button type="button" wire:click="removePhoto" class="text-xs font-semibold text-rose-600">Remove photo</button>
                        @endif
                        @error('photo') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid flex-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="label" for="i-name">Item name</label>
                            <input id="i-name" wire:model="f.name" class="input" autofocus placeholder="Black Forest Cake">
                            @error('f.name') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label" for="i-cat">Category</label>
                            <select id="i-cat" wire:model="f.category_id" class="input">
                                <option value="">— none —</option>
                                @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label" for="i-type">Food type</label>
                            <select id="i-type" wire:model="f.food_type" class="input">
                                <option value="">—</option>
                                @foreach (\App\Models\Item::FOOD_TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label" for="i-code">Short code <span class="font-normal text-slate-400">(optional)</span></label>
                            <input id="i-code" wire:model="f.code" class="input uppercase" placeholder="BF" maxlength="30">
                            @error('f.code') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label" for="i-bar">Barcode <span class="font-normal text-slate-400">(optional)</span></label>
                            <input id="i-bar" wire:model="f.barcode" class="input" inputmode="numeric" placeholder="scan or type">
                            @error('f.barcode') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                {{-- Price / sizes --}}
                <div class="rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                    <div class="flex flex-wrap items-end gap-4">
                        @if (! count($variants))
                            <div class="w-40">
                                <label class="label" for="i-price">Price</label>
                                <div class="relative">
                                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-400">{{ \App\Models\Setting::get('currency_symbol', '₹') }}</span>
                                    <input id="i-price" wire:model="f.price" type="number" step="0.01" min="0" class="input pl-7 tabular-nums">
                                </div>
                                @error('f.price') <p class="error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        <div class="w-36">
                            <label class="label" for="i-unit">Sold per</label>
                            <select id="i-unit" wire:model="f.unit" class="input">
                                @foreach (\App\Models\Item::UNITS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        <div class="ml-auto flex flex-wrap gap-2">
                            @foreach (\App\Support\Sales::sizeSets() as $set => $names)
                                <button type="button" wire:click="addSizes('{{ $set }}')" class="btn-ghost text-sm" title="{{ implode(', ', $names) }}">+ {{ $set === 'lb' ? 'Pound' : 'Kg' }} sizes</button>
                            @endforeach
                            <button type="button" wire:click="addVariant" class="btn-ghost text-sm"><x-icon name="plus" class="size-4" /> {{ count($variants) ? 'Add size' : 'Other size' }}</button>
                        </div>
                    </div>
                    @unless (count($variants))
                        <p class="mt-2 text-xs text-slate-500">
                            Cakes: tap {{ collect(\App\Support\Sales::sizeSets())->map(fn ($sizes, $set) => '“'.($set === 'lb' ? 'Pound' : 'Kg').' sizes” ('.implode(' / ', $sizes).')')->implode(' or ') }},
                            then type each price. Remove the sizes you don't sell.
                        </p>
                    @endunless
                    @if (count($variants))
                        <div class="mt-4 space-y-2">
                            <div class="hidden grid-cols-[1fr_8rem_9rem_2rem] gap-2 px-1 text-xs font-semibold uppercase text-slate-500 sm:grid"><span>Size</span><span>Price</span><span>Barcode</span><span></span></div>
                            @foreach ($variants as $i => $v)
                                <div wire:key="var-{{ $i }}" class="grid grid-cols-[1fr_7rem_2rem] gap-2 sm:grid-cols-[1fr_8rem_9rem_2rem]">
                                    <input wire:model="variants.{{ $i }}.name" class="input" placeholder="500 g" aria-label="Size name">
                                    <input wire:model="variants.{{ $i }}.price" type="number" step="0.01" min="0" class="input tabular-nums" placeholder="0" aria-label="Size price">
                                    <input wire:model="variants.{{ $i }}.barcode" class="input hidden sm:block" placeholder="optional" aria-label="Size barcode">
                                    <button type="button" wire:click="removeVariant({{ $i }})" class="grid place-items-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10" aria-label="Remove size"><x-icon name="x" class="size-4" /></button>
                                    @error("variants.$i.name") <p class="error col-span-full">{{ $message }}</p> @enderror
                                    @error("variants.$i.price") <p class="error col-span-full">{{ $message }}</p> @enderror
                                    @error("variants.$i.barcode") <p class="error col-span-full">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="i-gst">GST rate @unless ($gst)<span class="font-normal text-slate-400">(used when GST is on)</span>@endunless</label>
                        <select id="i-gst" wire:model="f.gst_rate" class="input">
                            @foreach (\App\Models\Item::GST_RATES as $r)<option value="{{ $r }}">{{ $r }}%</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="i-hsn">HSN code <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="i-hsn" wire:model="f.hsn" class="input" inputmode="numeric" placeholder="1905">
                        @error('f.hsn') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="i-desc">Description <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="i-desc" wire:model="f.description" class="input" maxlength="255" placeholder="Chocolate sponge, cherries, fresh cream">
                    </div>
                    @if ($stock)
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="f.track_stock" class="size-4 rounded border-slate-300 text-brand-500"> Track stock for this item</label>
                        @if ($f['track_stock'] ?? false)
                            <div>
                                <label class="label" for="i-low">Warn when stock falls to</label>
                                <input id="i-low" wire:model="f.low_stock_at" type="number" step="0.001" min="0" class="input" placeholder="e.g. 3">
                            </div>
                        @endif
                    @endif
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="f.is_active" class="size-4 rounded border-slate-300 text-brand-500"> Show on the POS</label>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <button type="button" wire:click="cancel" class="btn-ghost">Cancel</button>
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="save,photo">{{ $editingId ? 'Save' : 'Add item' }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Categories --}}
    @if ($showCategories)
        <x-modal title="Item categories" close="closeCategories">
            <form wire:submit="addCategory" class="flex gap-2">
                <input wire:model="newCategory" class="input" placeholder="New category, e.g. Pastries" autofocus>
                <button class="btn-primary shrink-0">Add</button>
            </form>
            @error('newCategory') <p class="error">{{ $message }}</p> @enderror
            @error('catDelete') <p class="error mt-2">{{ $message }}</p> @enderror
            <ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($categories as $c)
                    <li wire:key="cat-{{ $c->id }}" class="flex items-center gap-2 py-2">
                        @if ($catEditId === $c->id)
                            <input type="color" wire:model="catEditColor" class="size-8 shrink-0 cursor-pointer rounded border-0 bg-transparent" aria-label="Colour">
                            <input wire:model="catEditName" wire:keydown.enter.prevent="saveCategory" class="input py-1.5">
                            <button wire:click="saveCategory" class="btn-primary shrink-0 py-1.5">Save</button>
                        @else
                            <span class="size-3 shrink-0 rounded-full" style="background: {{ $c->color }}"></span>
                            <span @class(['flex-1 text-sm font-medium', 'text-slate-400 line-through' => ! $c->is_active])>{{ $c->name }} <span class="text-xs text-slate-400">{{ $c->items_count }}</span></span>
                            <button wire:click="moveCategory({{ $c->id }}, -1)" class="rounded p-1 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Move up">↑</button>
                            <button wire:click="moveCategory({{ $c->id }}, 1)" class="rounded p-1 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Move down">↓</button>
                            <button wire:click="editCategory({{ $c->id }})" class="rounded p-1 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Rename"><x-icon name="pencil" class="size-4" /></button>
                            <button wire:click="toggleCategory({{ $c->id }})" class="rounded px-1.5 py-1 text-xs font-semibold text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">{{ $c->is_active ? 'Hide' : 'Show' }}</button>
                            @can('delete-records')
                                <button wire:click="deleteCategory({{ $c->id }})" wire:confirm="Delete “{{ $c->name }}”?" class="rounded p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10" aria-label="Delete"><x-icon name="trash" class="size-4" /></button>
                            @endcan
                        @endif
                    </li>
                @empty
                    <li class="py-6 text-center text-sm text-slate-500">No categories yet. Add Cakes, Pastries, Snacks, Beverages…</li>
                @endforelse
            </ul>
            <p class="mt-3 text-xs text-slate-500">The order here is the order of the tabs on the POS.</p>
        </x-modal>
    @endif

    {{-- Import --}}
    @if ($showImport)
        <x-modal title="Import items from Excel / CSV">
            <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                <li><a href="{{ route('items.template') }}" class="font-semibold text-brand-600 hover:underline">Download the template</a> and fill one item per row.</li>
                <li>Sizes go in one cell: <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">500 g=450; 1 kg=850</code></li>
                <li>Existing items (same code, or same name) are updated; new ones are added.</li>
            </ol>
            <label class="mt-4 flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 px-4 py-8 text-center hover:border-brand-400 dark:border-slate-700">
                <x-icon name="upload" class="size-7 text-slate-400" />
                <span class="text-sm font-semibold">{{ $importFile ? $importFile->getClientOriginalName() : 'Choose a .xlsx or .csv file' }}</span>
                <input type="file" wire:model="importFile" accept=".csv,.xlsx,.xls" class="sr-only">
            </label>
            <p wire:loading wire:target="importFile" class="mt-2 text-sm text-slate-500">Reading…</p>
            @error('importFile') <p class="error">{{ $message }}</p> @enderror
            @if ($importPreview)
                <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-800/50">
                    <p class="font-semibold">{{ $importPreview['rows'] }} items found: {{ $importPreview['create'] }} new, {{ $importPreview['update'] }} to update.</p>
                    @if ($importPreview['errors'])
                        <ul class="mt-2 list-disc space-y-0.5 pl-5 text-rose-600">@foreach ($importPreview['errors'] as $e)<li>{{ $e }}</li>@endforeach</ul>
                        <p class="mt-2 text-xs text-slate-500">Fix these rows in the file and choose it again.</p>
                    @endif
                </div>
            @endif
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="cancel" class="btn-ghost">Cancel</button>
                <button wire:click="runImport" class="btn-primary" @disabled(! $importPreview || $importPreview['errors']) wire:loading.attr="disabled" wire:target="runImport,importFile">Import</button>
            </div>
        </x-modal>
    @endif

    @if ($confirmingDelete)
        <x-modal title="Delete item?" size="max-w-sm">
            <p class="text-sm text-slate-600 dark:text-slate-300">This removes the item and its sizes. To keep it for old bills but stop selling it, hide it instead.</p>
            <div class="mt-5 flex justify-end gap-2">
                <button wire:click="cancel" class="btn-ghost">Cancel</button>
                <button wire:click="delete" class="btn bg-rose-600 text-white hover:bg-rose-700">Delete</button>
            </div>
        </x-modal>
    @endif
</div>
