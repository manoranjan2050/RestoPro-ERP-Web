<!DOCTYPE html>
<html lang="en">
<head>
    @include('components.layouts.head')
</head>
<body class="min-h-screen" x-data="{ nav: false }">
@php
    $user = auth()->user();
    $business = \App\Models\Setting::get('business_name');
@endphp

{{-- Mobile backdrop --}}
<div x-cloak x-show="nav" x-transition.opacity @click="nav = false" class="fixed inset-0 z-30 bg-slate-900/50 backdrop-blur-sm lg:hidden"></div>

{{-- Sidebar --}}
<aside :class="nav ? 'translate-x-0' : '-translate-x-full'"
       class="overflow-hidden fixed inset-y-0 left-0 z-40 flex w-72 flex-col bg-slate-900 text-slate-300 transition-transform duration-200 lg:translate-x-0">
    <div class="flex h-16 items-center gap-3 px-5">
        <x-brand-mark />
        <div class="min-w-0">
            <p class="truncate text-sm font-bold text-white">{{ $business }}</p>
            <p class="truncate text-xs text-slate-400">{{ \App\Models\Setting::get('business_tagline') ?: 'OpenRestoPro ERP' }}</p>
        </div>
        <button class="ml-auto rounded-lg p-1.5 hover:bg-slate-800 lg:hidden" @click="nav = false" aria-label="Close menu"><x-icon name="x" /></button>
    </div>

    <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
        @php
            $advanced = \App\Support\Sales::advanced();
            $isCashier = $user->isCashier();
            // Which links this user sees: Advanced-only groups, feature switches, cashier = POS items only, gate checks.
            $navGroups = collect(config('restopro.nav'))
                ->reject(fn ($g) => ($g['mode'] ?? null) === 'advanced' && ! $advanced)
                ->map(fn ($g) => $g + ['visible' => collect($g['items'])->filter(fn ($i) =>
                    (! isset($i['feature']) || \App\Support\Sales::on($i['feature']))
                    && (! $isCashier || ! empty($i['pos']))
                    && ! (isset($i['can']) && \Illuminate\Support\Facades\Route::has($i['route']) && ! \Illuminate\Support\Facades\Gate::allows($i['can']))
                )->values()])
                ->filter(fn ($g) => $g['visible']->isNotEmpty());
        @endphp
        @foreach ($navGroups as $group)
            <div>
                <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $group['group'] }}</p>
                <ul class="space-y-1">
                    @foreach ($group['visible'] as $item)
                        @php
                            $exists = \Illuminate\Support\Facades\Route::has($item['route']);
                            $active = $exists && request()->routeIs($item['route'], $item['route'].'.*');
                        @endphp
                        <li>
                            @if ($exists)
                                <a href="{{ route($item['route']) }}" wire:navigate
                                   @class([
                                       'group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                                       'bg-gradient-to-r from-brand-500 to-rose-500 text-white shadow-lg shadow-brand-500/20' => $active,
                                       'hover:bg-slate-800 hover:text-white' => ! $active,
                                   ])>
                                    <x-icon :name="$item['icon']" />
                                    {{ $item['label'] }}
                                </a>
                            @else
                                <span class="flex cursor-not-allowed items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-500">
                                    <x-icon :name="$item['icon']" />
                                    {{ $item['label'] }}
                                    <span class="ml-auto rounded-full bg-slate-800 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-400">Soon</span>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    <div class="border-t border-slate-800 p-3">
        <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-3 rounded-xl p-2 hover:bg-slate-800">
            <x-avatar :user="$user" class="size-9" />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-white">{{ $user->name }}</p>
                <p class="truncate text-xs capitalize text-slate-400">{{ $user->role }}</p>
            </div>
        </a>
    </div>
</aside>

{{-- Main --}}
<div class="lg:pl-72">
    <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200/70 bg-white/80 px-4 backdrop-blur sm:px-6 dark:border-slate-800 dark:bg-slate-900/80">
        <button class="rounded-lg p-2 hover:bg-slate-100 lg:hidden dark:hover:bg-slate-800" @click="nav = true" aria-label="Open menu"><x-icon name="menu" /></button>
        <h1 class="truncate text-lg font-bold">{{ $title ?? 'Dashboard' }}</h1>

        <div class="ml-auto flex items-center gap-1.5">
            <x-theme-toggle />

            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button @click="open = !open" class="flex items-center gap-2 rounded-full p-0.5 ring-brand-500 focus-visible:ring-2" aria-label="Account menu">
                    <x-avatar :user="$user" class="size-9" />
                </button>
                <div x-cloak x-show="open" x-transition.origin.top.right
                     class="absolute right-0 mt-2 w-56 overflow-hidden rounded-2xl border border-slate-200 bg-white py-1 shadow-xl dark:border-slate-700 dark:bg-slate-800">
                    <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-700">
                        <p class="truncate text-sm font-semibold">{{ $user->name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ '@'.$user->username }}</p>
                    </div>
                    <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-2 px-4 py-2.5 text-sm hover:bg-slate-50 dark:hover:bg-slate-700"><x-icon name="user" class="size-4" /> My profile</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="flex w-full items-center gap-2 px-4 py-2.5 text-sm text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-slate-700"><x-icon name="logout" class="size-4" /> Sign out</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <main @class(["mx-auto p-4 sm:p-6", "max-w-7xl lg:p-8" => ! request()->routeIs("pos"), "max-w-none lg:p-5" => request()->routeIs("pos")])>
        {{ $slot }}
        <x-dev-credit class="mt-10 pb-2" />
    </main>
</div>

<x-toast />
@livewireScripts
</body>
</html>
