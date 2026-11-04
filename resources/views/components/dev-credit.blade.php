@props(['dark' => false])
{{-- "Developed by" line shown at the bottom of every page. --}}
<p {{ $attributes->merge(['class' => 'text-center text-xs '.($dark ? 'text-slate-400' : 'text-slate-400 dark:text-slate-500')]) }}>
    Developed by
    <a href="{{ route('developer') }}" class="group font-semibold tracking-wide text-slate-600 hover:text-brand-600 dark:text-slate-300 dark:hover:text-brand-400">
        <span class="bg-gradient-to-r from-brand-500 via-rose-500 to-violet-500 bg-clip-text text-transparent group-hover:opacity-80">MANORANJAN</span>
    </a>
    (<a href="https://manoranjan.dev" target="_blank" rel="noopener" class="hover:text-brand-600 hover:underline dark:hover:text-brand-400">manoranjan.dev</a>)
</p>
