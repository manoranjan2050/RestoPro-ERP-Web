@props(['title' => null, 'close' => 'cancel', 'size' => 'max-w-lg'])
{{-- Render conditionally from the parent (@if); closes via the given Livewire action. --}}
<div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true"
     x-data x-init="$nextTick(() => $el.querySelector('[autofocus]')?.focus())" @keydown.escape.window="$wire.{{ $close }}()">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" wire:click="{{ $close }}"></div>
    <div class="relative max-h-[92vh] w-full {{ $size }} overflow-y-auto rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl dark:bg-slate-900">
        @if ($title)
            <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-100 bg-white/95 px-5 py-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
                <h2 class="text-base font-bold">{{ $title }}</h2>
                <button type="button" wire:click="{{ $close }}" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800" aria-label="Close"><x-icon name="x" /></button>
            </div>
        @endif
        <div class="p-5">{{ $slot }}</div>
    </div>
</div>
