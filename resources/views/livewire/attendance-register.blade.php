@php $S = \App\Models\Attendance::STATUSES; @endphp
<div class="space-y-6">
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            @foreach (['day' => 'Daily register', 'month' => 'Month grid'] as $k => $l)
                <button wire:click="$set('view', '{{ $k }}')" @class([
                    'rounded-lg px-3 py-1.5 text-sm font-semibold',
                    'bg-gradient-to-r from-brand-500 to-rose-500 text-white shadow' => $view === $k,
                    'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' => $view !== $k,
                ])>{{ $l }}</button>
            @endforeach
        </div>
        <div class="flex items-center rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <button wire:click="shift(-1)" class="rounded-lg px-2.5 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Previous">‹</button>
            @if ($view === 'day')
                <input type="date" wire:model.live="date" max="{{ today()->toDateString() }}" class="w-40 border-0 bg-transparent py-1 text-center text-sm font-semibold focus:ring-0 dark:[color-scheme:dark]">
            @else
                <input type="month" wire:model.live="month" max="{{ now()->format('Y-m') }}" class="w-40 border-0 bg-transparent py-1 text-center text-sm font-semibold focus:ring-0 dark:[color-scheme:dark]">
            @endif
            <button wire:click="shift(1)" @disabled($isCurrent) class="rounded-lg px-2.5 py-1.5 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" aria-label="Next">›</button>
        </div>
        <div class="flex flex-wrap gap-1.5 text-[11px] sm:ml-auto">
            @foreach ($S as $code => [$name, $short, $cls])
                <span class="inline-flex items-center gap-1"><span class="grid size-5 place-items-center rounded-md font-bold {{ $cls }}">{{ $short }}</span>{{ $name }}</span>
            @endforeach
        </div>
    </div>

    @if ($staff->isEmpty())
        <div class="card px-5 py-14 text-center">
            <p class="font-semibold">No staff working on this {{ $view === 'day' ? 'day' : 'month' }}</p>
            <a href="{{ route('staff') }}" wire:navigate class="btn-primary mt-4">Go to Staff</a>
        </div>
    @elseif ($view === 'day')
        {{-- Daily register --}}
        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
                <div>
                    <h3 class="font-bold">{{ $label }}</h3>
                    <p class="text-xs text-slate-500">
                        @foreach ($summary as $code => $n) @if ($n) {{ $S[$code][0] }} {{ $n }} · @endif @endforeach
                        {{ $unmarked }} not marked
                    </p>
                </div>
                @if ($unmarked)
                    <button wire:click="markAllPresent" class="btn-primary ml-auto py-2"><x-icon name="check" class="size-4" /> Mark rest present</button>
                @endif
            </div>
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($staff as $s)
                    @php $cur = $marks[$s->id] ?? null; @endphp
                    <li wire:key="d-{{ $s->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5">
                        <a href="{{ route('staff.show', $s) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3">
                            @if ($s->photoUrl())
                                <img src="{{ $s->photoUrl() }}" class="size-10 shrink-0 rounded-xl object-cover" alt="">
                            @else
                                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-sky-400 to-indigo-500 text-sm font-bold text-white">{{ $s->initials() }}</span>
                            @endif
                            <span class="min-w-0">
                                <span class="block truncate font-semibold">{{ $s->name }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ $s->designation ?: 'Staff' }}</span>
                            </span>
                        </a>
                        <div class="flex w-full gap-1.5 sm:w-auto">
                            @foreach ($S as $code => [$name, $short, $cls])
                                <button wire:click="mark({{ $s->id }}, '{{ $code }}')" title="{{ $name }}" @class([
                                    'h-10 flex-1 rounded-xl text-sm font-bold transition sm:w-11 sm:flex-none',
                                    $cls.' shadow-md scale-105' => $cur === $code,
                                    'bg-slate-100 text-slate-500 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:hover:bg-slate-700' => $cur !== $code,
                                ])>{{ $short }}</button>
                            @endforeach
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
        <p class="text-xs text-slate-500">Tap a status again to clear it. Monthly-salary staff are paid for unmarked days; daily-wage staff only for Present (and half for Half day).</p>
    @else
        {{-- Month grid --}}
        <div class="card overflow-hidden">
            <div class="border-b border-slate-100 p-4 dark:border-slate-800">
                <h3 class="font-bold">{{ $label }}</h3>
                <p class="text-xs text-slate-500">Tap a cell to cycle: blank → P → H → A → L → O → blank</p>
            </div>
            <div class="overflow-x-auto">
                <table class="text-xs">
                    <thead>
                        <tr class="bg-slate-50 dark:bg-slate-800/50">
                            <th class="sticky left-0 z-10 min-w-36 bg-slate-50 px-3 py-2 text-left font-semibold dark:bg-slate-800">Staff</th>
                            @for ($d = 1; $d <= $days; $d++)
                                @php $dt = $start->copy()->day($d); @endphp
                                <th @class(['w-8 px-0.5 py-2 text-center font-semibold', 'text-rose-500' => $dt->isSunday(), 'text-brand-600' => $dt->isToday()])>
                                    {{ $d }}<br><span class="font-normal text-slate-400">{{ mb_substr($dt->format('D'), 0, 1) }}</span>
                                </th>
                            @endfor
                            <th class="px-2 py-2 text-center font-semibold">P</th>
                            <th class="px-2 py-2 text-center font-semibold">A</th>
                            <th class="px-2 py-2 text-center font-semibold">H</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($staff as $s)
                            @php $row = $grid[$s->id] ?? collect(); @endphp
                            <tr wire:key="g-{{ $s->id }}">
                                <td class="sticky left-0 z-10 bg-white px-3 py-1.5 dark:bg-slate-900">
                                    <a href="{{ route('staff.show', $s) }}" wire:navigate class="block max-w-36 truncate font-semibold hover:underline">{{ $s->name }}</a>
                                </td>
                                @for ($d = 1; $d <= $days; $d++)
                                    @php
                                        $dt = $start->copy()->day($d);
                                        $code = $row[$d] ?? null;
                                        $inRange = $dt->gte($s->joining_date) && (! $s->leaving_date || $dt->lte($s->leaving_date)) && ! $dt->isFuture();
                                    @endphp
                                    <td class="p-0.5 text-center">
                                        @if ($inRange)
                                            <button wire:click="cycle({{ $s->id }}, '{{ $dt->toDateString() }}')"
                                                    class="grid size-7 place-items-center rounded-md font-bold {{ $code ? $S[$code][2] : 'bg-slate-100 text-slate-300 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-600' }}"
                                                    title="{{ $dt->format('d M') }}{{ $code ? ' · '.$S[$code][0] : '' }}">{{ $code ?? '·' }}</button>
                                        @else
                                            <span class="grid size-7 place-items-center text-slate-200 dark:text-slate-700">–</span>
                                        @endif
                                    </td>
                                @endfor
                                <td class="px-2 text-center font-bold text-emerald-600">{{ $row->filter(fn ($c) => $c === 'P')->count() }}</td>
                                <td class="px-2 text-center font-bold text-rose-600">{{ $row->filter(fn ($c) => $c === 'A')->count() }}</td>
                                <td class="px-2 text-center font-bold text-amber-600">{{ $row->filter(fn ($c) => $c === 'H')->count() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
