@props(['k', 'connected', 'testResult' => null])
@if ($connected)
    <div class="mt-3 flex gap-2">
        <button wire:click="testCloud('{{ $k }}')" wire:loading.attr="disabled" wire:target="testCloud('{{ $k }}')" class="btn-ghost flex-1 py-2">
            <span wire:loading.remove wire:target="testCloud('{{ $k }}')">Test connection</span><span wire:loading wire:target="testCloud('{{ $k }}')">Testing…</span>
        </button>
        <button wire:click="disconnect('{{ $k }}')" wire:confirm="Disconnect? Automatic uploads here stop. Files already uploaded stay." class="btn py-2 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">Disconnect</button>
    </div>
@endif
@if ($testResult)
    <p @class(['mt-2 rounded-xl px-3 py-2 text-xs',
               'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $testResult['ok'],
               'bg-rose-50 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300' => ! $testResult['ok']])>{{ $testResult['ok'] ? '✓ ' : '✗ ' }}{{ $testResult['message'] }}</p>
@endif
