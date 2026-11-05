@php
    $hour = (int) now()->format('G');
    $greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $split = ['labels' => ['Cash', 'UPI', 'Online'], 'values' => [round($month['cash'], 2), round($month['upi'], 2), round($month['online'], 2)]];
    $expSplit = ['labels' => array_column($byCategory, 'name'), 'values' => array_map(fn ($a) => round($a, 2), array_column($byCategory, 'amount')), 'colors' => array_column($byCategory, 'color')];
@endphp
<div class="space-y-6">
    {{-- Hero --}}
    <div class="relative overflow-clip rounded-3xl bg-gradient-to-br from-brand-500 via-rose-500 to-violet-600 p-6 text-white shadow-xl shadow-brand-500/20 sm:p-8">
        <div class="pointer-events-none absolute -right-10 -top-10 size-48 rounded-full bg-white/10"></div>
        <div class="pointer-events-none absolute -bottom-16 right-24 size-40 rounded-full bg-white/10"></div>
        <div class="relative flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-medium text-white/80">{{ now()->format('l, d F Y') }}</p>
                <h2 class="mt-1 text-2xl font-extrabold sm:text-3xl">{{ $greet }}, {{ explode(' ', auth()->user()->name)[0] }} 👋</h2>
                <p class="mt-2 max-w-xl text-sm text-white/85">
                    @if ($today)
                        Today's collection is in: <b>{{ money($today->total) }}</b>.
                    @else
                        Today's collection hasn't been entered yet.
                    @endif
                </p>
            </div>
            <a href="{{ route('collections') }}" wire:navigate class="btn shrink-0 bg-white text-brand-600 shadow-lg hover:bg-brand-50">
                <x-icon name="plus" class="size-4" /> {{ $today ? 'View collections' : "Add today's collection" }}
            </a>
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        @foreach ($stats as $s)
            <x-stat :label="$s['label']" :value="$s['value']" :icon="$s['icon']" :tone="$s['tone']" :hint="$s['hint']" />
        @endforeach
    </div>

    {{-- Counter today (Advanced mode) --}}
    @if ($pos)
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card p-5">
                <div class="flex items-center justify-between">
                    <h3 class="font-bold">Counter today</h3>
                    <a href="{{ route('pos') }}" wire:navigate class="text-sm font-semibold text-brand-600 hover:underline">Open POS →</a>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"><p class="text-slate-500">Bills</p><p class="text-xl font-extrabold tabular-nums">{{ $pos['bills'] }}</p></div>
                    <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"><p class="text-slate-500">Average bill</p><p class="text-xl font-extrabold tabular-nums">{{ money($pos['avg']) }}</p></div>
                    @if ($pos['tables'] !== null)<a href="{{ route('tables') }}" wire:navigate class="rounded-xl bg-orange-50 p-3 hover:ring-1 hover:ring-orange-300 dark:bg-orange-500/10"><p class="text-slate-500">Tables running</p><p class="text-xl font-extrabold tabular-nums text-orange-600">{{ $pos['tables'] }}</p></a>@endif
                    @if ($pos['deliveries'] !== null)<a href="{{ route('orders', ['tab' => 'delivery']) }}" wire:navigate class="rounded-xl bg-sky-50 p-3 hover:ring-1 hover:ring-sky-300 dark:bg-sky-500/10"><p class="text-slate-500">Deliveries open</p><p class="text-xl font-extrabold tabular-nums text-sky-600">{{ $pos['deliveries'] }}</p></a>@endif
                </div>
                <a href="{{ route('reports', ['type' => 'day_end']) }}" wire:navigate class="btn-ghost mt-4 w-full justify-center">Day-end report</a>
            </div>

            <div class="card p-5">
                <h3 class="font-bold">Top items today</h3>
                <ul class="mt-3 space-y-2">
                    @forelse ($pos['top'] as $i => $t)
                        <li class="flex items-center gap-3 text-sm">
                            <span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-50 text-xs font-bold text-brand-600 dark:bg-brand-500/10">{{ $i + 1 }}</span>
                            <span class="min-w-0 flex-1 truncate">{{ $t->name }}{{ $t->variant_name ? ' · '.$t->variant_name : '' }} <span class="text-xs text-slate-400">× {{ rtrim(rtrim(number_format((float) $t->qty, 3), '0'), '.') }}</span></span>
                            <span class="font-semibold tabular-nums">{{ money($t->net) }}</span>
                        </li>
                    @empty
                        <li class="py-6 text-center text-sm text-slate-500">No bills yet today.</li>
                    @endforelse
                </ul>
            </div>

            <div class="card p-5">
                @if ($pos['advance']->isNotEmpty())
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold">Advance orders due</h3>
                        <a href="{{ route('orders', ['tab' => 'advance']) }}" wire:navigate class="text-sm font-semibold text-brand-600 hover:underline">All →</a>
                    </div>
                    <ul class="mt-3 space-y-2">
                        @foreach ($pos['advance'] as $o)
                            <li class="text-sm">
                                <span @class(['font-semibold', 'text-rose-600' => $o->due_at?->isPast()])>{{ $o->due_at?->isToday() ? 'Today' : ($o->due_at?->isTomorrow() ? 'Tomorrow' : $o->due_at?->format('d M')) }} {{ $o->due_at?->format('h:i A') }}</span>
                                · {{ $o->customer_name }} <span class="text-xs text-slate-500">· balance {{ money($o->balance()) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($pos['low']->isNotEmpty())
                    <div @class(['flex items-center justify-between', 'mt-5' => $pos['advance']->isNotEmpty()])>
                        <h3 class="font-bold">Low stock</h3>
                        <a href="{{ route('stock', ['lowOnly' => 1]) }}" wire:navigate class="text-sm font-semibold text-brand-600 hover:underline">Stock →</a>
                    </div>
                    <ul class="mt-3 space-y-1.5">
                        @foreach ($pos['low'] as $r)
                            <li class="flex justify-between text-sm"><span class="truncate">{{ $r['label'] }}</span><span @class(['font-semibold tabular-nums', 'text-rose-600' => $r['qty'] <= 0, 'text-amber-600' => $r['qty'] > 0])>{{ rtrim(rtrim(number_format($r['qty'], 3), '0'), '.') }}</span></li>
                        @endforeach
                    </ul>
                @endif
                @if ($pos['advance']->isEmpty() && $pos['low']->isEmpty())
                    <h3 class="font-bold">All clear</h3>
                    <p class="mt-3 text-sm text-slate-500">No advance orders due in the next two days and nothing low on stock.</p>
                @endif
            </div>
        </div>
    @endif

    {{-- Charts --}}
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card p-5 lg:col-span-2">
            <div class="flex items-center justify-between">
                <h3 class="font-bold">Last 14 days</h3>
                <a href="{{ route('revenue', ['preset' => '30d']) }}" wire:navigate class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">Revenue log →</a>
            </div>
            <div x-data="apexChart('bars', @js($trend))" class="-mx-2 mt-2 min-h-[240px]"></div>
        </div>
        <div class="card p-5">
            <h3 class="font-bold">{{ now()->format('F') }} payment split</h3>
            @if ($month['total'] > 0)
                <div x-data="apexChart('split', @js($split))" class="mt-2 min-h-[280px]"></div>
            @else
                <p class="grid h-[260px] place-items-center text-sm text-slate-500">No sales yet this month</p>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
    {{-- Recent entries --}}
    <div class="card overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <h3 class="font-bold">Recent collections</h3>
            <a href="{{ route('collections') }}" wire:navigate class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">See all →</a>
        </div>
        @forelse ($recent as $r)
            <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                <div class="grid size-10 place-items-center rounded-xl bg-emerald-50 text-center leading-none text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                    <span class="text-sm font-bold">{{ $r->date->format('d') }}<br><span class="text-[10px] font-semibold uppercase">{{ $r->date->format('M') }}</span></span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold">{{ $r->date->format('l') }}</p>
                    <p class="truncate text-xs text-slate-500">Cash {{ money($r->cash) }} · UPI {{ money($r->upi) }} · Online {{ money($r->online) }}</p>
                </div>
                <p class="font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ money($r->total) }}</p>
            </div>
        @empty
            <p class="px-5 py-10 text-center text-sm text-slate-500">No collections yet. Add your first one from Cash Collection.</p>
        @endforelse
    </div>
    </div>
    <div class="card p-5">
        <div class="flex items-center justify-between">
            <h3 class="font-bold">{{ now()->format('F') }} expenses</h3>
            <a href="{{ route('expenses') }}" wire:navigate class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">Expenses →</a>
        </div>
        @if (count($byCategory))
            <div x-data="apexChart('split', @js($expSplit))" class="mt-2 min-h-[280px]"></div>
        @else
            <p class="grid h-[220px] place-items-center text-sm text-slate-500">No expenses yet this month</p>
        @endif
        <a href="{{ route('vendors', ['show' => 'due']) }}" wire:navigate class="mt-3 flex items-center justify-between rounded-xl bg-rose-50 px-4 py-3 text-sm dark:bg-rose-500/10">
            <span class="font-medium text-rose-700 dark:text-rose-300">Owed to vendors</span>
            <span class="font-extrabold tabular-nums text-rose-700 dark:text-rose-300">{{ money($allDue) }}</span>
        </a>
    </div>
    </div>

    {{-- Android app download (once the owner uploads the APK in Settings → Android app) --}}
    <x-app-download />
</div>
