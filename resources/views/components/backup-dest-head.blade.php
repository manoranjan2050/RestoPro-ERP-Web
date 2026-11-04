@props(['label', 'connected', 'enabled', 'k'])
<div class="flex items-start justify-between gap-3">
    <div>
        <h3 class="font-bold">{{ $label }}</h3>
        <p @class(['mt-0.5 text-xs font-semibold',
                   'text-emerald-600 dark:text-emerald-400' => $connected && $enabled,
                   'text-amber-600 dark:text-amber-400' => $connected && ! $enabled,
                   'text-slate-400' => ! $connected])>
            {{ $connected ? ($enabled ? '● Connected · uploading' : '● Connected · paused') : '○ Not connected' }}
        </p>
    </div>
    @if ($connected)
        <button wire:click="toggleCloud('{{ $k }}')" class="rounded-lg px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800">{{ $enabled ? 'Pause' : 'Resume' }}</button>
    @endif
</div>
