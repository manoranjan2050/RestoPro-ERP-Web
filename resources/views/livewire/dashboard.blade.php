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
</div>
