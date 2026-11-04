@props(['label', 'value', 'icon', 'tone' => 'emerald', 'hint' => null])
@php
    $tones = [
        'emerald' => 'from-emerald-400 to-teal-500 shadow-emerald-500/30',
        'sky' => 'from-sky-400 to-blue-500 shadow-sky-500/30',
        'rose' => 'from-rose-400 to-pink-500 shadow-rose-500/30',
        'violet' => 'from-violet-400 to-purple-500 shadow-violet-500/30',
        'amber' => 'from-amber-400 to-orange-500 shadow-amber-500/30',
        'indigo' => 'from-indigo-400 to-blue-600 shadow-indigo-500/30',
    ];
@endphp
<div {{ $attributes->merge(['class' => 'card p-4 transition hover:-translate-y-0.5 hover:shadow-lg sm:p-5']) }}>
    <div class="flex items-center justify-between gap-2">
        <p class="min-w-0 truncate text-xs font-medium text-slate-500 sm:text-sm dark:text-slate-400">{{ $label }}</p>
        <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br text-white shadow-lg sm:size-10 sm:rounded-xl {{ $tones[$tone] ?? $tones['emerald'] }}">
            <x-icon :name="$icon" class="size-4 sm:size-5" />
        </span>
    </div>
    {{-- Full card width for the figure so large amounts (₹12,34,567) never truncate on phones. --}}
    <p class="mt-2 break-all text-lg font-extrabold leading-tight tabular-nums sm:text-2xl">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1.5 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">{{ $hint }}</p>
    @endif
</div>
