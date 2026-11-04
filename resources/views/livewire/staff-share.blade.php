@php
    $S = \App\Models\Attendance::STATUSES;
    $T = \App\Models\StaffPayment::TYPES;
    $first = explode(' ', $staff->name)[0];
@endphp
<div class="space-y-5">
    {{-- Identity --}}
    <div class="card overflow-hidden">
        <div class="h-16 bg-gradient-to-r from-brand-500 via-rose-500 to-violet-600"></div>
        <div class="-mt-8 flex items-start gap-4 px-5 pb-5">
            @if ($staff->photoUrl())
                <img src="{{ $staff->photoUrl() }}" alt="" class="size-16 rounded-2xl object-cover ring-4 ring-white dark:ring-slate-900">
            @else
                <span class="grid size-16 place-items-center rounded-2xl bg-gradient-to-br from-sky-400 to-indigo-500 text-xl font-extrabold text-white ring-4 ring-white dark:ring-slate-900">{{ $staff->initials() }}</span>
            @endif
            <div class="min-w-0 pt-10">
                <h1 class="truncate text-lg font-extrabold">{{ $staff->name }}</h1>
                <p class="truncate text-sm text-slate-500">{{ $staff->designation ?: 'Staff' }} · {{ $business }}</p>
            </div>
        </div>
    </div>

    @if ($locked)
        <form wire:submit="unlock" class="card p-6 text-center">
            <div class="mx-auto grid size-14 place-items-center rounded-2xl bg-brand-50 text-brand-500 dark:bg-brand-500/10"><x-icon name="lock" class="size-7" /></div>
            <p class="mt-3 font-semibold">Enter your 4-digit PIN</p>
            <p class="mt-1 text-sm text-slate-500">Ask the owner if you don't know it.</p>
            <input wire:model="pin" inputmode="numeric" maxlength="4" autocomplete="one-time-code" autofocus
                   class="input mx-auto mt-4 w-40 text-center font-mono text-2xl tracking-[0.5em]" placeholder="••••">
            @error('pin') <p class="error">{{ $message }}</p> @enderror
            <button class="btn-primary mt-4 w-40">Open</button>
        </form>
    @else
        {{-- Balance --}}
        <div @class(['rounded-3xl p-6 text-white shadow-xl',
                     'bg-gradient-to-br from-rose-500 to-pink-600 shadow-rose-500/20' => $balance > 0,
                     'bg-gradient-to-br from-amber-500 to-orange-600 shadow-amber-500/20' => $balance < 0,
                     'bg-gradient-to-br from-emerald-500 to-teal-600 shadow-emerald-500/20' => $balance == 0])>
            <p class="text-sm font-medium text-white/85">
                {{ $balance > 0 ? 'Salary due to you' : ($balance < 0 ? 'Advance taken (to be adjusted)' : 'All settled') }}
            </p>
            <p class="mt-1 text-4xl font-extrabold tabular-nums">{{ money(abs($balance)) }}</p>
            <p class="mt-2 text-xs text-white/80">As of {{ now()->format('d M Y, h:i A') }} · {{ $staff->rateLabel() }}</p>
        </div>

        {{-- Month --}}
        <div class="card p-5">
            <div class="flex items-center justify-between gap-3">
                <button wire:click="shiftMonth(-1)" @disabled($month <= $staff->joining_date->format('Y-m')) class="rounded-xl px-3 py-2 text-lg hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" aria-label="Previous month">‹</button>
                <h2 class="font-bold">{{ $start->format('F Y') }}</h2>
                <button wire:click="shiftMonth(1)" @disabled($month >= now()->format('Y-m')) class="rounded-xl px-3 py-2 text-lg hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" aria-label="Next month">›</button>
            </div>

            <div class="mt-4 grid grid-cols-7 gap-1.5 text-center text-[11px]">
                @foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $i => $d)
                    <div @class(['font-semibold text-slate-500', 'text-rose-500' => $i === 6])>{{ $d }}</div>
                @endforeach
                @for ($i = 1; $i < $start->dayOfWeekIso; $i++) <div></div> @endfor
                @for ($d = 1; $d <= $start->daysInMonth; $d++)
                    @php
                        $dt = $start->copy()->day($d);
                        $code = $marks[$d] ?? null;
                        $ok = $dt->gte($staff->joining_date) && (! $staff->leaving_date || $dt->lte($staff->leaving_date)) && ! $dt->isFuture();
                    @endphp
                    <div @class(['flex aspect-square flex-col items-center justify-center rounded-lg text-xs font-bold',
                                 $code ? $S[$code][2] : ($ok ? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' : 'text-slate-300 dark:text-slate-700')])>
                        {{ $d }}
                        @if ($code) <span class="text-[9px] font-semibold opacity-90">{{ $S[$code][1] }}</span> @endif
                    </div>
                @endfor
            </div>
            <div class="mt-3 flex flex-wrap justify-center gap-x-3 gap-y-1 text-[11px] text-slate-500">
                @foreach ($S as $code => [$name, $short, $cls])
                    <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded {{ $cls }}"></span>{{ $name }} {{ $current['counts'][$code] }}</span>
                @endforeach
            </div>

            <dl class="mt-5 space-y-2 border-t border-slate-100 pt-4 text-sm dark:border-slate-800">
                <div class="flex justify-between"><dt class="text-slate-500">Carried from last month</dt><dd class="font-semibold tabular-nums">{{ money($current['opening']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Earned this month <span class="text-xs">({{ $current['paid_days'] }} {{ $staff->salary_type === 'daily' ? 'paid' : 'of '.$current['days_in_month'] }} days{{ $current['in_progress'] ? ', so far' : '' }})</span></dt><dd class="font-semibold tabular-nums">{{ money($current['work_value']) }}</dd></div>
                @if ($current['bonus']) <div class="flex justify-between"><dt class="text-slate-500">Bonus</dt><dd class="font-semibold tabular-nums">{{ money($current['bonus']) }}</dd></div> @endif
                @if ($current['deductions']) <div class="flex justify-between"><dt class="text-slate-500">Deductions</dt><dd class="font-semibold tabular-nums text-rose-600">−{{ money($current['deductions']) }}</dd></div> @endif
                <div class="flex justify-between"><dt class="text-slate-500">Received this month</dt><dd class="font-semibold tabular-nums text-emerald-600">−{{ money($current['paid']) }}</dd></div>
                <div class="flex justify-between border-t border-slate-100 pt-2 text-base dark:border-slate-800">
                    <dt class="font-bold">Balance at month end</dt>
                    <dd class="font-extrabold tabular-nums">{{ money($current['closing']) }}</dd>
                </div>
            </dl>
        </div>

        {{-- Payments --}}
        <div class="card overflow-hidden">
            <h2 class="border-b border-slate-100 px-5 py-4 font-bold dark:border-slate-800">Payments received</h2>
            @forelse ($payments as $p)
                <div class="flex items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                    <div class="w-14 shrink-0 text-xs text-slate-500">{{ $p->date->format('d M') }}<br>{{ $p->date->format('Y') }}</div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">{{ $T[$p->type][0] }}
                            @if ($p->for_month) <span class="font-normal text-slate-500">for {{ \Illuminate\Support\Carbon::parse($p->for_month.'-01')->format('M Y') }}</span> @endif
                        </p>
                        @if ($p->note) <p class="truncate text-xs text-slate-500">{{ $p->note }}</p> @endif
                    </div>
                    <p @class(['font-bold tabular-nums', 'text-rose-600' => $p->type === 'deduction'])>{{ $p->type === 'deduction' ? '−' : '' }}{{ money($p->amount) }}</p>
                </div>
            @empty
                <p class="px-5 py-8 text-center text-sm text-slate-500">No payments yet.</p>
            @endforelse
        </div>

        {{-- Year --}}
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-bold">Yearly ledger</h2>
            <select wire:model.live="fy" class="input w-auto">
                @foreach ($years as $y => $l) <option value="{{ $y }}">{{ $l }}</option> @endforeach
            </select>
        </div>
        <x-staff-ledger-table :rows="$yearRows" :totals="$yearTotals" :label="$years[$fy] ?? ''" />
    @endif
</div>
