<div class="space-y-6">
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-stat label="Active staff" :value="$activeCount" icon="users" tone="sky" :hint="$markedToday.' marked today'" />
        <x-stat label="Monthly salaries" :value="money($monthlyBill)" icon="cash" tone="violet" hint="Fixed monthly pay (daily wages extra)" />
        <x-stat label="Salary owed" :value="money($owed)" icon="receipt" tone="rose" hint="Earned but not yet paid" />
        <x-stat label="Advances out" :value="money($advances)" icon="trending" tone="amber" hint="Paid ahead, to be recovered" />
    </div>

    <div class="card overflow-hidden">
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 p-4 dark:border-slate-800">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name, mobile, role…" class="input w-full sm:w-64">
            <div class="inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1 dark:border-slate-700 dark:bg-slate-800">
                @foreach (['active' => 'Active', 'left' => 'Left', 'all' => 'All'] as $k => $label)
                    <button wire:click="$set('show', '{{ $k }}')" @class([
                        'rounded-lg px-3 py-1 text-sm font-semibold',
                        'bg-white text-brand-600 shadow-sm dark:bg-slate-900 dark:text-brand-400' => $show === $k,
                        'text-slate-500' => $show !== $k,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
            <div class="flex w-full gap-2 sm:ml-auto sm:w-auto">
                <a href="{{ route('attendance') }}" wire:navigate class="btn-ghost flex-1 sm:flex-none"><x-icon name="check" class="size-4" /> Attendance</a>
                <button wire:click="createStaff" class="btn-primary flex-1 sm:flex-none"><x-icon name="plus" class="size-4" /> Add staff</button>
            </div>
        </div>

        @if ($cards->isEmpty())
            <div class="px-5 py-14 text-center">
                <div class="mx-auto grid size-14 place-items-center rounded-2xl bg-sky-50 text-sky-500 dark:bg-sky-500/10"><x-icon name="users" class="size-7" /></div>
                <p class="mt-3 font-semibold">{{ $show === 'active' && ! $search ? 'No staff yet' : 'Nobody matches' }}</p>
                <p class="mt-1 text-sm text-slate-500">Add your cooks, helpers and waiters to track attendance, salary and advances.</p>
                @if ($show === 'active' && ! $search) <button wire:click="createStaff" class="btn-primary mt-5"><x-icon name="plus" class="size-4" /> Add staff</button> @endif
            </div>
        @else
            <ul class="grid gap-px bg-slate-100 sm:grid-cols-2 xl:grid-cols-3 dark:bg-slate-800">
                @foreach ($cards as $c)
                    @php $s = $c['staff']; $b = $c['balance']; $st = $c['today'] ? \App\Models\Attendance::STATUSES[$c['today']] : null; @endphp
                    <li wire:key="s-{{ $s->id }}" class="bg-white dark:bg-slate-900">
                        <a href="{{ route('staff.show', $s) }}" wire:navigate class="flex items-start gap-3 p-4 transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                            @if ($s->photoUrl())
                                <img src="{{ $s->photoUrl() }}" alt="{{ $s->name }}" class="size-12 shrink-0 rounded-2xl object-cover">
                            @else
                                <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-sky-400 to-indigo-500 font-bold text-white">{{ $s->initials() }}</span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p @class(['truncate font-semibold', 'text-slate-400' => ! $s->is_active])>{{ $s->name }}</p>
                                <p class="truncate text-xs text-slate-500">{{ $s->designation ?: 'Staff' }} · {{ $s->rateLabel() }}</p>
                                <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                                    @if ($st)
                                        <span class="rounded-full px-2 py-0.5 font-semibold {{ $st[2] }}">Today: {{ $st[0] }}</span>
                                    @elseif ($s->is_active)
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-500 dark:bg-slate-800">Today: not marked</span>
                                    @else
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-500 dark:bg-slate-800">Left {{ $s->leaving_date?->format('d M Y') }}</span>
                                    @endif
                                    <span class="text-slate-500">{{ now()->format('M') }}: {{ money($c['month_earned']) }} earned</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <p @class(['font-extrabold tabular-nums', 'text-rose-600 dark:text-rose-400' => $b > 0, 'text-amber-600 dark:text-amber-400' => $b < 0, 'text-emerald-600 dark:text-emerald-400' => $b == 0])>{{ money(abs($b)) }}</p>
                                <p class="text-[11px] text-slate-500">{{ $b > 0 ? 'to pay' : ($b < 0 ? 'advance' : 'settled') }}</p>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($showStaffForm)
        <x-staff-form :staff-id="$staffId" :sf="$sf" :staff-photo="$staffPhoto" />
    @endif
</div>
