@php
    $cur = \App\Models\Setting::get('currency_symbol', '₹');
    $count = collect($cart)->sum(fn ($c) => (float) $c['qty']);
    $foodDot = ['veg' => 'border-emerald-600 after:bg-emerald-600', 'egg' => 'border-amber-500 after:bg-amber-500', 'nonveg' => 'border-rose-600 after:bg-rose-600'];
    $qtyLabel = fn ($q) => rtrim(rtrim(number_format((float) $q, 3), '0'), '.');
@endphp
<div x-data="{
        cartOpen: false,
        // Prints through a hidden frame on this page, so no pop-up window is needed.
        printIn(frame, url) { const f = this.$refs[frame]; f.src = 'about:blank'; setTimeout(() => { f.src = url }, 30) },
     }"
     @keydown.window.f2.prevent="$refs.search.focus()"
     @keydown.window.f8.prevent="$wire.openPay()"
     @print-kot.window="printIn('kot', $event.detail.url)"
     @bill-saved.window="cartOpen = false; if ($event.detail.print) printIn('printer', $event.detail.print); if ($event.detail.kot) setTimeout(() => printIn('kot', $event.detail.kot), 1500)"
     class="lg:grid lg:h-[calc(100vh-6.5rem)] lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-5">

    {{-- ============ Items ============ --}}
    <section class="flex min-h-0 flex-col gap-3">
        <div class="flex gap-2">
            <div class="relative flex-1">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><x-icon name="search" class="size-4" /></span>
                <input x-ref="search" wire:model.live.debounce.250ms="search" wire:keydown.enter.prevent="scan" type="search" autofocus
                       class="input py-2.5 pl-9" placeholder="Search, short code or scan barcode  (F2)">
            </div>
            <button wire:click="$set('showHeld', true)" class="btn-ghost relative shrink-0">
                On hold
                @if ($held->count())<span class="ml-1 rounded-full bg-amber-500 px-1.5 text-xs font-bold text-white">{{ $held->count() }}</span>@endif
            </button>
        </div>

        <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
            <button wire:click="$set('category', '')" @class(['shrink-0 rounded-xl px-4 py-2 text-sm font-semibold transition',
                'bg-slate-900 text-white shadow dark:bg-white dark:text-slate-900' => $category === '',
                'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800' => $category !== ''])>All</button>
            @foreach ($categories as $c)
                <button wire:key="pc-{{ $c->id }}" wire:click="$set('category', '{{ $c->id }}')"
                        @class(['shrink-0 rounded-xl px-4 py-2 text-sm font-semibold transition',
                            'text-white shadow' => $category === (string) $c->id,
                            'bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800' => $category !== (string) $c->id])
                        @if ($category === (string) $c->id) style="background: {{ $c->color }}" @endif>{{ $c->name }}</button>
            @endforeach
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto pb-24 lg:pb-2">
            @if ($items->isEmpty())
                <div class="card px-6 py-12 text-center text-sm text-slate-500">
                    @if (\App\Models\Item::count() === 0)
                        No items yet. <a href="{{ route('items') }}" wire:navigate class="font-semibold text-brand-600">Add your menu</a> first.
                    @else
                        Nothing matches “{{ $search }}”.
                    @endif
                </div>
            @else
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                    @foreach ($items as $item)
                        @php $inCart = collect($cart)->where('item_id', $item->id)->sum(fn ($c) => (float) $c['qty']); @endphp
                        <button wire:key="pi-{{ $item->id }}" wire:click="add({{ $item->id }})"
                                class="card group relative flex flex-col overflow-hidden text-left transition active:scale-[.98] hover:shadow-md hover:ring-2 hover:ring-brand-400">
                            <span class="relative block aspect-[4/3] w-full overflow-hidden bg-slate-100 dark:bg-slate-800">
                                @if ($item->photoUrl())
                                    <img src="{{ $item->photoUrl() }}" alt="" class="size-full object-cover" loading="lazy">
                                @else
                                    <span class="grid size-full place-items-center text-3xl font-extrabold text-white/90" style="background: {{ $item->category?->color ?? '#94a3b8' }}">{{ mb_strtoupper(mb_substr($item->name, 0, 1)) }}</span>
                                @endif
                                @if ($inCart)
                                    <span class="absolute right-2 top-2 grid min-w-7 place-items-center rounded-full bg-brand-500 px-1.5 py-0.5 text-xs font-bold text-white shadow">{{ $qtyLabel($inCart) }}</span>
                                @endif
                            </span>
                            <span class="flex flex-1 flex-col p-2.5">
                                <span class="flex items-start gap-1.5">
                                    @if ($item->food_type)<span class="relative mt-0.5 size-3 shrink-0 rounded-sm border-2 after:absolute after:inset-[2px] after:rounded-full {{ $foodDot[$item->food_type] }}"></span>@endif
                                    <span class="line-clamp-2 text-sm font-semibold leading-tight">{{ $item->name }}</span>
                                </span>
                                <span class="mt-auto pt-1 text-sm font-bold text-brand-600 dark:text-brand-400">{{ $item->priceLabel() }}</span>
                                @if ($item->variants->isNotEmpty())<span class="text-[11px] text-slate-500">{{ $item->variants->count() }} sizes</span>@endif
                                @if ($stockOn && $item->track_stock)
                                    @php $left = $item->stockOnHand(); @endphp
                                    <span @class(['text-[11px] font-semibold', 'text-rose-600' => $left <= 0, 'text-amber-600' => $left > 0 && $item->isLowStock(), 'text-emerald-600' => $left > 0 && ! $item->isLowStock()])>{{ $left <= 0 ? 'Out of stock' : $qtyLabel($left).' left' }}</span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- Mobile cart bar --}}
    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 p-3 backdrop-blur lg:hidden dark:border-slate-800 dark:bg-slate-900/95">
        <button @click="cartOpen = true" class="btn-primary w-full justify-between py-3 text-base">
            <span>{{ $count ? $qtyLabel($count).' item'.($count == 1 ? '' : 's') : 'Bill is empty' }}</span>
            <span class="flex items-center gap-2 font-extrabold">{{ money($live['total']) }} <span aria-hidden="true">→</span></span>
        </button>
    </div>
    <div x-cloak x-show="cartOpen" x-transition.opacity @click="cartOpen = false" class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"></div>

    {{-- ============ Bill ============ --}}
    <aside :class="cartOpen ? 'translate-y-0' : 'translate-y-full lg:translate-y-0'"
           class="fixed inset-x-0 bottom-0 z-50 flex max-h-[92vh] flex-col rounded-t-3xl bg-white shadow-2xl transition-transform lg:static lg:z-auto lg:max-h-none lg:rounded-3xl lg:shadow-sm lg:ring-1 lg:ring-slate-200 dark:bg-slate-900 lg:dark:ring-slate-800">
        <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
            <div class="flex items-center gap-2">
                @if ($current)
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                        {{ $current->status === 'held' ? 'Resumed' : 'Running' }} #{{ $current->id }}{{ $current->table ? ' · '.$current->table->name : '' }}
                    </span>
                @endif
                <button @click="cartOpen = false" class="ml-auto rounded-lg p-1 text-slate-400 lg:hidden" aria-label="Close bill"><x-icon name="x" /></button>
                @if ($cart || $current)
                    <button wire:click="clear" wire:confirm="{{ $current && $current->status === 'open' ? 'Leave this order? It stays saved as a running order.' : 'Clear this bill?' }}" class="ml-auto hidden text-xs font-semibold text-rose-600 hover:underline lg:block">{{ $current && $current->status === 'open' ? 'Close' : 'Clear' }}</button>
                @endif
            </div>
            <div class="mt-2 flex flex-wrap gap-1 text-xs font-semibold">
                @foreach ($types as $k => $l)
                    <button wire:click="setType('{{ $k }}')" @disabled($current && $current->status === 'open' && $current->type !== $k)
                        @class(['rounded-lg px-2.5 py-1.5 ring-1 transition disabled:opacity-30',
                            'bg-slate-900 text-white ring-slate-900 dark:bg-white dark:text-slate-900' => $type === $k,
                            'text-slate-600 ring-slate-200 hover:ring-slate-400 dark:text-slate-300 dark:ring-slate-700' => $type !== $k])>{{ $l }}</button>
                @endforeach
            </div>
        </div>

        {{-- Customer + type-specific details --}}
        <div class="max-h-[40vh] space-y-2 overflow-y-auto border-b border-slate-100 px-4 py-3 dark:border-slate-800 lg:max-h-none">
            @if ($type === 'dine_in')
                <div class="grid grid-cols-[1fr_5rem] gap-2">
                    <select wire:model.live="table_id" class="input py-2 text-sm" aria-label="Table">
                        <option value="">Choose table…</option>
                        @foreach ($tables->groupBy(fn ($t) => $t->area ?: 'Tables') as $area => $group)
                            <optgroup label="{{ $area }}">
                                @foreach ($group as $t)
                                    @php $busy = $t->openOrder && $t->openOrder->id !== $orderId; @endphp
                                    <option value="{{ $t->id }}" @disabled($busy)>{{ $t->name }}{{ $busy ? ' (busy)' : '' }} · {{ $t->seats }} seats</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <input wire:model.blur="guests" type="number" min="1" max="99" class="input py-2 text-sm" placeholder="Guests" aria-label="Guests">
                </div>
                @error('table_id') <p class="error">{{ $message }}</p> @enderror
                @if ($tables->isEmpty())<p class="text-xs text-slate-500">No tables yet — add them on the <a href="{{ route('tables') }}" wire:navigate class="font-semibold text-brand-600">Tables</a> page.</p>@endif
            @endif

            <div class="grid grid-cols-2 gap-2">
                <input wire:model.live.debounce.400ms="customer_mobile" inputmode="numeric" class="input py-2 text-sm" placeholder="Customer mobile" aria-label="Customer mobile">
                <input wire:model.blur="customer_name" class="input py-2 text-sm" placeholder="{{ in_array($type, ['advance']) ? 'Name' : 'Name (optional)' }}" aria-label="Customer name">
            </div>
            @error('customer_mobile') <p class="error">{{ $message }}</p> @enderror

            @if ($type === 'delivery')
                <textarea wire:model.blur="delivery_address" rows="2" class="input py-2 text-sm" placeholder="Delivery address" aria-label="Delivery address"></textarea>
                <div class="flex items-center gap-2 text-sm">
                    <span class="text-slate-500">Delivery charge</span>
                    <input wire:model.live.debounce.400ms="delivery_charge" type="number" min="0" step="1" class="input w-24 py-1.5 text-sm" placeholder="0" aria-label="Delivery charge">
                </div>
                @error('delivery_address') <p class="error">{{ $message }}</p> @enderror
            @endif

            @if ($type === 'advance')
                <div class="grid grid-cols-2 gap-2">
                    <input wire:model.blur="due_date" type="date" min="{{ today()->toDateString() }}" class="input py-2 text-sm dark:[color-scheme:dark]" aria-label="Pickup date">
                    <input wire:model.blur="due_time" type="time" class="input py-2 text-sm dark:[color-scheme:dark]" aria-label="Pickup time">
                </div>
                @error('due_at') <p class="error">{{ $message }}</p> @enderror
                <div class="grid grid-cols-2 gap-2">
                    <input wire:model.blur="details.flavour" class="input py-2 text-sm" placeholder="Flavour" aria-label="Flavour">
                    <input wire:model.blur="details.weight" class="input py-2 text-sm" placeholder="Weight (e.g. 1 kg)" aria-label="Weight">
                    <input wire:model.blur="details.shape" class="input py-2 text-sm" placeholder="Shape / design" aria-label="Shape">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="details.eggless" class="size-4 rounded border-slate-300 text-brand-500"> Eggless</label>
                </div>
                <input wire:model.blur="details.message" class="input py-2 text-sm" maxlength="200" placeholder="Message on cake: Happy Birthday Riya" aria-label="Message on cake">
                <input wire:model.blur="details.instructions" class="input py-2 text-sm" maxlength="200" placeholder="Other instructions" aria-label="Instructions">
            @endif
        </div>

        {{-- Lines --}}
        <div class="min-h-[8rem] flex-1 overflow-y-auto px-2 py-1">
            @forelse ($cart as $i => $c)
                @php $p = $priced[$c['key']] ?? null; @endphp
                <div wire:key="line-{{ $c['key'] }}" class="group rounded-xl px-2 py-2 hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold leading-tight">{{ $p['name'] ?? 'Item' }}@if ($p['variant_name'] ?? null) <span class="font-normal text-slate-500">· {{ $p['variant_name'] }}</span>@endif</p>
                            <p class="text-xs text-slate-500">{{ money($p['rate'] ?? 0) }}{{ ($p['unit'] ?? 'pcs') !== 'pcs' ? ' / '.$p['unit'] : '' }}</p>
                            @if ($c['note'] !== '' && $noteFor !== $c['key'])<p class="text-xs italic text-amber-600">“{{ $c['note'] }}”</p>@endif
                        </div>
                        <div class="flex items-center rounded-lg ring-1 ring-slate-200 dark:ring-slate-700">
                            <button wire:click="dec('{{ $c['key'] }}')" class="px-2 py-1 text-lg leading-none text-slate-500 hover:text-rose-600" aria-label="Less">−</button>
                            <input wire:key="q-{{ $c['key'] }}-{{ $c['qty'] }}" value="{{ $qtyLabel($c['qty']) }}" wire:change="setQty('{{ $c['key'] }}', $event.target.value)"
                                   type="number" step="{{ in_array($p['unit'] ?? 'pcs', ['kg', 'g', 'ltr']) ? '0.001' : '1' }}" min="0"
                                   class="w-12 border-0 bg-transparent p-0 text-center text-sm font-bold tabular-nums focus:ring-0" aria-label="Quantity">
                            <button wire:click="inc('{{ $c['key'] }}')" class="px-2 py-1 text-lg leading-none text-slate-500 hover:text-emerald-600" aria-label="More">+</button>
                        </div>
                        <p class="w-20 text-right text-sm font-bold tabular-nums">{{ money($p['calc']['amount'] ?? 0) }}</p>
                    </div>
                    <div class="mt-1 flex gap-3 pl-0.5 text-[11px] font-semibold text-slate-400">
                        <button wire:click="$set('noteFor', '{{ $noteFor === $c['key'] ? '' : $c['key'] }}')" class="hover:text-amber-600">{{ $c['note'] !== '' ? 'Edit note' : '+ Note' }}</button>
                        <button wire:click="remove('{{ $c['key'] }}')" class="hover:text-rose-600">Remove</button>
                        @if (! empty($c['kot_no']))<span class="ml-auto rounded bg-emerald-50 px-1.5 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">KOT {{ $c['kot_no'] }} ✓</span>@elseif ($type === 'dine_in')<span class="ml-auto rounded bg-amber-50 px-1.5 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">new</span>@endif
                    </div>
                    @if ($noteFor === $c['key'])
                        <input value="{{ $c['note'] }}" wire:change="setNote('{{ $c['key'] }}', $event.target.value)" wire:keydown.enter="setNote('{{ $c['key'] }}', $event.target.value)" class="input mt-1 py-1.5 text-sm" placeholder="less sugar, happy birthday Riya…" maxlength="120" autofocus>
                    @endif
                </div>
            @empty
                <div class="flex h-full flex-col items-center justify-center gap-2 py-8 text-center text-sm text-slate-400">
                    <x-icon name="cart" class="size-8" />
                    Tap items to add them to the bill
                </div>
            @endforelse
        </div>

        {{-- Totals --}}
        <div class="space-y-1.5 border-t border-slate-100 px-4 py-3 text-sm dark:border-slate-800">
            <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span class="tabular-nums">{{ money($live['subtotal']) }}</span></div>
            <div class="flex items-center justify-between gap-2">
                <span class="text-slate-500">Discount</span>
                <div class="flex items-center gap-1">
                    <input wire:model.live.debounce.400ms="discount_value" type="number" min="0" step="0.01" class="input w-20 py-1 text-right text-sm" placeholder="0" aria-label="Discount">
                    <div class="inline-flex rounded-md bg-slate-100 p-0.5 text-xs font-semibold dark:bg-slate-800">
                        <button wire:click="$set('discount_type', 'amt')" @class(['rounded px-1.5 py-0.5', 'bg-white shadow dark:bg-slate-700' => $discount_type === 'amt'])>{{ $cur }}</button>
                        <button wire:click="$set('discount_type', 'pct')" @class(['rounded px-1.5 py-0.5', 'bg-white shadow dark:bg-slate-700' => $discount_type === 'pct'])>%</button>
                    </div>
                    @if ($live['discount'] > 0)<span class="w-16 text-right tabular-nums text-rose-600">−{{ money($live['discount']) }}</span>@endif
                </div>
            </div>
            @error('discount') <p class="error">{{ $message }}</p> @enderror
            @if ($gst && $live['tax'] > 0)
                <div class="flex justify-between text-xs text-slate-500"><span>CGST</span><span class="tabular-nums">{{ money($live['cgst']) }}</span></div>
                <div class="flex justify-between text-xs text-slate-500"><span>SGST</span><span class="tabular-nums">{{ money($live['sgst']) }}</span></div>
                @if (\App\Support\Sales::gstInclusive())<p class="text-[11px] text-slate-400">GST included in prices</p>@endif
            @endif
            @if ($type === 'delivery' && (float) $delivery_charge > 0)
                <div class="flex justify-between text-xs text-slate-500"><span>Delivery</span><span class="tabular-nums">{{ money((float) $delivery_charge) }}</span></div>
            @endif
            @if ($live['round_off'] != 0)
                <div class="flex justify-between text-xs text-slate-500"><span>Round off</span><span class="tabular-nums">{{ $live['round_off'] > 0 ? '+' : '' }}{{ number_format($live['round_off'], 2) }}</span></div>
            @endif
            <div class="flex items-end justify-between pt-1">
                <span class="font-semibold">Total</span>
                <span class="text-2xl font-extrabold tabular-nums">{{ money($live['total']) }}</span>
            </div>
            @if ($prior > 0)
                <div class="flex justify-between text-xs font-semibold text-emerald-600"><span>Advance paid</span><span class="tabular-nums">−{{ money($prior) }}</span></div>
                <div class="flex justify-between font-bold"><span>Balance</span><span class="tabular-nums">{{ money($balance) }}</span></div>
            @endif
            @error('cart') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-2 border-t border-slate-100 p-3 dark:border-slate-800">
            @if ($type === 'dine_in')
                <button wire:click="sendKot" @disabled(! $cart || ! $unsent) class="btn-ghost flex-1 justify-center disabled:opacity-40">🧾 Send KOT</button>
            @elseif (in_array($type, ['delivery', 'advance']))
                <button wire:click="openPay('open')" @disabled(! $cart) class="btn-ghost flex-1 justify-center disabled:opacity-40">{{ $current && $current->status === 'open' ? 'Save changes' : ($type === 'advance' ? 'Book (advance)' : 'Save order') }}</button>
            @elseif (! $current || $current->status === 'held')
                <button wire:click="hold" @disabled(! $cart) class="btn-ghost disabled:opacity-40">Hold</button>
            @endif
            <button wire:click="openPay" @disabled(! $cart) class="btn-primary flex-[2] justify-center py-3 text-base disabled:opacity-40">
                {{ $type === 'dine_in' && $current ? 'Settle' : 'Pay' }} {{ money(max(0, $balance)) }} <span class="hidden text-xs opacity-70 lg:inline">(F8)</span>
            </button>
        </div>
    </aside>

    {{-- ============ Size picker ============ --}}
    @if ($picking)
        <x-modal :title="$picking->name" close="closePicker" size="max-w-md">
            <div class="grid grid-cols-2 gap-3">
                @foreach ($picking->variants as $v)
                    <button wire:key="pv-{{ $v->id }}" wire:click="add({{ $picking->id }}, {{ $v->id }})" class="rounded-2xl p-4 text-left ring-1 ring-slate-200 transition hover:bg-brand-50 hover:ring-brand-400 dark:ring-slate-700 dark:hover:bg-brand-500/10">
                        <span class="block font-bold">{{ $v->name }}</span>
                        <span class="block text-lg font-extrabold text-brand-600 dark:text-brand-400">{{ money($v->price) }}</span>
                        @if ($stockOn && $picking->track_stock)<span @class(['block text-xs font-semibold', 'text-rose-600' => (float) $v->stock_qty <= 0, 'text-slate-500' => (float) $v->stock_qty > 0])>{{ (float) $v->stock_qty <= 0 ? 'Out of stock' : $qtyLabel($v->stock_qty).' left' }}</span>@endif
                    </button>
                @endforeach
            </div>
        </x-modal>
    @endif

    {{-- ============ Payment ============ --}}
    @if ($showPay)
        <x-modal title="Payment" close="closePay" size="max-w-md">
            <div class="rounded-2xl bg-gradient-to-r from-brand-500 to-rose-500 p-4 text-white">
                <p class="text-sm opacity-90">{{ $payMode === 'open' ? 'Advance now (optional) · order total '.money($live['total']) : 'Amount to collect' }}</p>
                <p class="text-3xl font-extrabold tabular-nums">{{ money($payMode === 'open' ? $paidSum : $balance) }}</p>
                @if ($prior > 0)<p class="text-xs opacity-90">{{ money($prior) }} already paid</p>@endif
            </div>
            <div class="mt-4 grid grid-cols-4 gap-2">
                @foreach (\App\Models\OrderPayment::MODES as $m => [$label])
                    <button wire:click="payAll('{{ $m }}')" @class(['rounded-xl py-2.5 text-sm font-semibold ring-1 transition',
                        'bg-slate-900 text-white ring-slate-900 dark:bg-white dark:text-slate-900' => count($payments) === 1 && ($payments[0]['mode'] ?? '') === $m,
                        'ring-slate-200 hover:ring-slate-400 dark:ring-slate-700' => ! (count($payments) === 1 && ($payments[0]['mode'] ?? '') === $m)])>{{ $label }}</button>
                @endforeach
            </div>

            <div class="mt-4 space-y-2">
                @foreach ($payments as $i => $p)
                    <div wire:key="pay-{{ $i }}" class="flex gap-2">
                        <select wire:model.live="payments.{{ $i }}.mode" class="input w-28" aria-label="Mode">
                            @foreach (\App\Models\OrderPayment::MODES as $m => [$label])<option value="{{ $m }}">{{ $label }}</option>@endforeach
                        </select>
                        <div class="relative flex-1">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-400">{{ $cur }}</span>
                            <input wire:model.live.debounce.300ms="payments.{{ $i }}.amount" type="number" min="0" step="0.01" class="input pl-7 tabular-nums" aria-label="Amount">
                        </div>
                        @if (count($payments) > 1)
                            <button wire:click="removeSplit({{ $i }})" class="rounded-lg px-2 text-slate-400 hover:text-rose-600" aria-label="Remove"><x-icon name="x" class="size-4" /></button>
                        @endif
                    </div>
                @endforeach
                <button wire:click="addSplit" class="text-sm font-semibold text-brand-600 hover:underline">+ Split payment</button>
            </div>

            @php $diff = $payMode === 'open' ? 0.0 : round($balance - $paidSum, 2); $over = round($paidSum - $balance, 2); $cashIn = collect($payments)->where('mode', 'cash')->sum(fn ($p) => (float) ($p['amount'] ?: 0)); @endphp
            @if ($cashIn > 0)
                <div class="mt-4 flex items-center gap-2 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/50">
                    <span class="text-slate-500">Cash given</span>
                    <input wire:model.live.debounce.300ms="tendered" type="number" min="0" step="1" class="input w-28 py-1.5 tabular-nums" placeholder="{{ $cashIn }}" aria-label="Cash given">
                    @if ((float) $tendered > $cashIn)
                        <span class="ml-auto font-bold text-emerald-600">Return {{ money((float) $tendered - $cashIn) }}</span>
                    @endif
                </div>
            @endif
            @if (abs($diff) > 0.009)
                <p class="mt-3 text-sm font-semibold {{ $diff > 0 ? 'text-amber-600' : 'text-rose-600' }}">{{ $diff > 0 ? money($diff).' still to collect' : money(-$diff).' more than the bill' }}</p>
            @endif
            @if ($payMode === 'open')
                <p class="mt-3 text-sm {{ $over > 0.009 ? 'font-semibold text-rose-600' : 'text-slate-500' }}">{{ $over > 0.009 ? money($over).' more than the order' : 'Balance on '.($type === 'advance' ? 'pickup' : 'delivery').': '.money(max(0, $balance - $paidSum)) }}</p>
            @endif
            @error('payments') <p class="error mt-2">{{ $message }}</p> @enderror
            @error('cart') <p class="error mt-2">{{ $message }}</p> @enderror
            @error('discount') <p class="error mt-2">{{ $message }}</p> @enderror

            <div class="mt-5 flex gap-2">
                <button wire:click="$set('showPay', false)" class="btn-ghost">Back</button>
                <button wire:click="complete" wire:loading.attr="disabled" wire:target="complete" @disabled(abs($diff) > 0.009 || ($payMode === 'open' && $over > 0.009)) class="btn-primary flex-1 justify-center py-3 text-base disabled:opacity-40">
                    <span wire:loading.remove wire:target="complete">{{ $payMode === 'open' ? ($type === 'advance' ? 'Book order' : 'Save order') : 'Save bill' }}</span><span wire:loading wire:target="complete">Saving…</span>
                </button>
            </div>
        </x-modal>
    @endif

    {{-- ============ Held bills ============ --}}
    @if ($showHeld)
        <x-modal title="Bills on hold" close="closeHeld">
            @forelse ($held as $h)
                <div wire:key="h-{{ $h->id }}" class="flex items-center gap-3 border-b border-slate-100 py-3 last:border-0 dark:border-slate-800">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">#{{ $h->id }} · {{ money($h->total) }} <span class="font-normal text-slate-500">· {{ $h->items_count }} item(s)</span></p>
                        <p class="truncate text-xs text-slate-500">{{ $h->customer_name ?: 'Walk-in' }} · {{ $h->updated_at->diffForHumans() }}</p>
                    </div>
                    <button wire:click="resume({{ $h->id }})" class="btn-primary py-1.5 text-sm">Resume</button>
                    <button wire:click="discardHeld({{ $h->id }})" wire:confirm="Discard held bill #{{ $h->id }}?" class="rounded-lg p-1.5 text-slate-400 hover:text-rose-600" aria-label="Discard"><x-icon name="trash" class="size-4" /></button>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-slate-500">No bills on hold.</p>
            @endforelse
        </x-modal>
    @endif

    {{-- ============ Saved ============ --}}
    @if ($last)
        <x-modal close="dismissLast" size="max-w-sm">
            <div class="text-center">
                <span class="mx-auto grid size-16 place-items-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15"><x-icon name="check" class="size-8" /></span>
                <h2 class="mt-3 text-xl font-extrabold">{{ $last->status === 'completed' ? 'Bill saved' : ($last->type === 'advance' ? 'Order booked' : 'Order saved') }}</h2>
                <p class="text-sm text-slate-500">{{ $last->ref() }} · {{ money($last->total) }}{{ $last->balance() > 0.009 ? ' · balance '.money($last->balance()) : '' }}</p>
                @if ($last->due_at)<p class="text-sm font-semibold text-brand-600">Pickup {{ $last->due_at->format('D d M, h:i A') }}</p>@endif
                <div class="mt-5 grid grid-cols-2 gap-2">
                    <button type="button" @click="printIn('printer', '{{ route('orders.print', $last) }}?auto=1')" class="btn-ghost justify-center">🖨️ Print</button>
                    @if ($wa = \App\Services\BillView::whatsappUrl($last))
                        <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn-ghost justify-center text-emerald-600">WhatsApp</a>
                    @endif
                    <a href="{{ route('orders.pdf', $last) }}?view=1" target="_blank" class="btn-ghost justify-center">PDF</a>
                    <a href="{{ route('orders.show', $last) }}" wire:navigate class="btn-ghost justify-center">View bill</a>
                    <button wire:click="dismissLast" autofocus class="btn-primary col-span-2 justify-center py-3">New bill</button>
                </div>
            </div>
        </x-modal>
    @endif
    {{-- Hidden frames used for printing (see printIn) --}}
    <div wire:ignore>
    <iframe x-ref="printer" title="Bill printer" class="pointer-events-none fixed -left-[9999px] top-0 h-[1px] w-[400px] opacity-0" aria-hidden="true" tabindex="-1"></iframe>
    <iframe x-ref="kot" title="Kitchen printer" class="pointer-events-none fixed -left-[9999px] top-0 h-[1px] w-[400px] opacity-0" aria-hidden="true" tabindex="-1"></iframe>
    </div>
</div>
