@php
    $S = \App\Models\Attendance::STATUSES;
    $T = \App\Models\StaffPayment::TYPES;
    $typeBadge = [
        'salary' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400',
        'advance' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
        'bonus' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-400',
        'deduction' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400',
    ];
    $s = $staff;
@endphp
<div class="space-y-6">
    <a href="{{ route('staff') }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-semibold text-slate-500 hover:text-slate-800 dark:hover:text-slate-200">‹ All staff</a>

    {{-- Header --}}
    <div class="card overflow-hidden">
        <div class="h-20 bg-gradient-to-r from-sky-500 via-indigo-500 to-violet-600"></div>
        <div class="-mt-10 flex flex-col gap-4 px-5 pb-5 sm:flex-row sm:items-start">
            @if ($s->photoUrl())
                <img src="{{ $s->photoUrl() }}" alt="{{ $s->name }}" class="size-20 rounded-3xl object-cover ring-4 ring-white dark:ring-slate-900">
            @else
                <span class="grid size-20 place-items-center rounded-3xl bg-gradient-to-br from-sky-400 to-indigo-500 text-2xl font-extrabold text-white ring-4 ring-white dark:ring-slate-900">{{ $s->initials() }}</span>
            @endif
            <div class="min-w-0 flex-1 sm:pt-11">
                <h2 class="flex flex-wrap items-center gap-2 text-xl font-extrabold">
                    {{ $s->name }}
                    @unless ($s->is_active) <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-slate-500 dark:bg-slate-800">Inactive</span> @endunless
                </h2>
                <p class="text-sm text-slate-500">
                    {{ $s->designation ?: 'Staff' }} · {{ $s->rateLabel() }} · joined {{ $s->joining_date->format('d M Y') }}
                    @if ($s->leaving_date) · left {{ $s->leaving_date->format('d M Y') }} @endif
                </p>
                <p class="mt-1 flex flex-wrap gap-x-3 text-sm">
                    @if ($s->mobile) <a href="tel:{{ $s->mobile }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">📞 {{ $s->mobile }}</a> @endif
                    @if ($s->id_number) <span class="text-slate-500">{{ $s->id_type ?: 'ID' }}: {{ $s->id_number }}</span> @endif
                </p>
            </div>
            <div class="flex flex-col items-start gap-2 sm:items-end sm:pt-11">
                <div class="sm:text-right">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $balance > 0 ? 'Salary to pay' : ($balance < 0 ? 'Advance to recover' : 'Balance') }}</p>
                    <p @class(['text-3xl font-extrabold tabular-nums', 'text-rose-600 dark:text-rose-400' => $balance > 0, 'text-amber-600 dark:text-amber-400' => $balance < 0, 'text-emerald-600 dark:text-emerald-400' => $balance == 0])>{{ money(abs($balance)) }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button wire:click="editStaff({{ $s->id }})" class="btn-ghost py-2">Edit</button>
                    <button wire:click="openShare" class="btn-ghost py-2" title="Share link for staff"><x-icon name="cloud" class="size-4" /> Share</button>
                    <button wire:click="newPayment('advance')" class="btn-ghost py-2">Advance</button>
                    <button wire:click="newPayment('salary')" class="btn-primary py-2"><x-icon name="cash" class="size-4" /> Pay salary</button>
                </div>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="flex gap-1 overflow-x-auto border-t border-slate-100 px-3 dark:border-slate-800">
            @foreach (['overview' => 'Overview', 'attendance' => 'Attendance', 'payments' => 'Payments', 'ledger' => 'Ledger'] as $k => $l)
                <button wire:click="$set('tab', '{{ $k }}')" @class([
                    'border-b-2 px-4 py-3 text-sm font-semibold transition',
                    'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $k,
                    'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' => $tab !== $k,
                ])>{{ $l }}</button>
            @endforeach
        </div>
    </div>

    {{-- Month picker (overview + attendance) --}}
    @if ($tab === 'ledger')
        <div class="flex flex-wrap items-center gap-3">
            <select wire:model.live="fy" class="input w-auto font-semibold">
                @foreach ($years as $y => $l) <option value="{{ $y }}">{{ $l }}</option> @endforeach
            </select>
            <span class="text-sm text-slate-500">Monthly transaction ledger with carry-forward</span>
        </div>
        <x-staff-ledger-table :rows="$yearRows" :totals="$yearTotals" :label="$fyLabel" />
    @endif

    @if (! in_array($tab, ['payments', 'ledger'], true))
        <div class="flex items-center gap-3">
            <div class="flex items-center rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <button wire:click="shiftMonth(-1)" @disabled($month <= $minMonth) class="rounded-lg px-2.5 py-1.5 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" aria-label="Previous month">‹</button>
                <input type="month" wire:model.live="month" min="{{ $minMonth }}" max="{{ now()->format('Y-m') }}" class="w-40 border-0 bg-transparent py-1 text-center text-sm font-semibold focus:ring-0 dark:[color-scheme:dark]">
                <button wire:click="shiftMonth(1)" @disabled($isCurrentMonth) class="rounded-lg px-2.5 py-1.5 hover:bg-slate-100 disabled:opacity-30 dark:hover:bg-slate-800" aria-label="Next month">›</button>
            </div>
            @if ($current['in_progress']) <span class="rounded-full bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">In progress · up to today</span> @endif
        </div>
    @endif

    @if ($tab === 'overview')
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <x-stat label="Work value" :value="money($current['work_value'])" icon="spark" tone="violet"
                    :hint="($s->salary_type === 'daily' ? $current['paid_days'].' paid day(s) × '.money($current['per_day']) : $current['paid_days'].' of '.$current['days_in_month'].' days × '.money($current['per_day']).'/day')" />
            <x-stat label="Paid this month" :value="money($current['paid'])" icon="cash" tone="emerald"
                    :hint="'Salary '.money($current['salary_paid']).' · advance '.money($current['advance_paid'])" />
            <x-stat label="Opening (carried in)" :value="money($current['opening'])" icon="trending" tone="sky" hint="From previous month" />
            <x-stat label="Closing balance" :value="money($current['closing'])" icon="book" :tone="$current['closing'] < 0 ? 'amber' : 'rose'"
                    :hint="$current['closing'] > 0 ? 'To pay' : ($current['closing'] < 0 ? 'Advance carried forward' : 'Settled')" />
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="card p-5">
                <h3 class="font-bold">Attendance · {{ $current['label'] }}</h3>
                <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                    @foreach ($S as $code => [$name, $short, $cls])
                        <div class="rounded-xl bg-slate-50 p-2 dark:bg-slate-800/60">
                            <span class="mx-auto grid size-6 place-items-center rounded-md text-[11px] font-bold {{ $cls }}">{{ $short }}</span>
                            <p class="mt-1 text-lg font-extrabold">{{ $current['counts'][$code] }}</p>
                            <p class="text-[10px] text-slate-500">{{ $name }}</p>
                        </div>
                    @endforeach
                    <div class="rounded-xl bg-slate-50 p-2 dark:bg-slate-800/60">
                        <span class="mx-auto grid size-6 place-items-center rounded-md bg-slate-200 text-[11px] font-bold text-slate-500 dark:bg-slate-700">?</span>
                        <p class="mt-1 text-lg font-extrabold">{{ $current['unmarked'] }}</p>
                        <p class="text-[10px] text-slate-500">Not marked</p>
                    </div>
                </div>
                <button wire:click="$set('tab', 'attendance')" class="mt-4 w-full text-sm font-semibold text-brand-600 hover:underline dark:text-brand-400">Open calendar →</button>
            </div>

            <div class="card p-5 lg:col-span-2">
                <h3 class="font-bold">How {{ $current['label'] }} adds up</h3>
                <dl class="mt-4 space-y-2 text-sm">
                    @foreach ([
                        ['Opening balance (carried in)', $current['opening'], ''],
                        ['+ Work value', $current['work_value'], ''],
                        ['+ Bonus', $current['bonus'], ''],
                        ['− Deductions', -$current['deductions'], 'text-rose-600 dark:text-rose-400'],
                        ['− Salary paid', -$current['salary_paid'], 'text-rose-600 dark:text-rose-400'],
                        ['− Advance paid', -$current['advance_paid'], 'text-rose-600 dark:text-rose-400'],
                        ['− Bonus paid', -$current['bonus'], 'text-rose-600 dark:text-rose-400'],
                    ] as [$l, $v, $cls])
                        @if ($v != 0 || str_contains($l, 'Opening') || str_contains($l, 'Work'))
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ $l }}</dt><dd class="font-semibold tabular-nums {{ $cls }}">{{ money($v) }}</dd></div>
                        @endif
                    @endforeach
                    <div class="flex justify-between gap-3 border-t border-slate-200 pt-2 text-base dark:border-slate-700">
                        <dt class="font-bold">Closing balance</dt>
                        <dd @class(['font-extrabold tabular-nums', 'text-rose-600 dark:text-rose-400' => $current['closing'] > 0, 'text-amber-600 dark:text-amber-400' => $current['closing'] < 0])>{{ money($current['closing']) }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-slate-500">Positive = you owe {{ explode(' ', $s->name)[0] }}. Negative = advance still to recover. The closing balance carries into next month.</p>
            </div>
        </div>
    @endif

    @if ($tab === 'attendance')
        <div class="card p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-bold">{{ $start->format('F Y') }}</h3>
                <p class="text-xs text-slate-500">Tap a day to cycle: blank → P → H → A → L → O</p>
            </div>
            <div class="mt-4 grid grid-cols-7 gap-1.5 text-center text-xs sm:gap-2">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)
                    <div @class(['pb-1 font-semibold text-slate-500', 'text-rose-500' => $d === 'Sun'])>{{ $d }}</div>
                @endforeach
                @for ($i = 1; $i < $start->dayOfWeekIso; $i++) <div></div> @endfor
                @for ($d = 1; $d <= $start->daysInMonth; $d++)
                    @php
                        $dt = $start->copy()->day($d);
                        $code = $marks[$d] ?? null;
                        $ok = $dt->gte($s->joining_date) && (! $s->leaving_date || $dt->lte($s->leaving_date)) && ! $dt->isFuture();
                    @endphp
                    @if ($ok)
                        <button wire:click="toggleDay('{{ $dt->toDateString() }}')" wire:key="cal-{{ $d }}"
                                @class(['flex aspect-square flex-col items-center justify-center rounded-xl font-bold transition sm:aspect-auto sm:h-16',
                                        $code ? $S[$code][2] : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300',
                                        'ring-2 ring-brand-500 ring-offset-2 dark:ring-offset-slate-900' => $dt->isToday()])>
                            <span class="text-sm">{{ $d }}</span>
                            <span class="text-[10px] font-semibold opacity-90">{{ $code ? $S[$code][0] : '' }}</span>
                        </button>
                    @else
                        <div class="flex aspect-square items-center justify-center rounded-xl text-sm text-slate-300 sm:aspect-auto sm:h-16 dark:text-slate-700">{{ $d }}</div>
                    @endif
                @endfor
            </div>
            <div class="mt-4 flex flex-wrap gap-3 text-xs text-slate-500">
                @foreach ($S as $code => [$name, $short, $cls])
                    <span class="inline-flex items-center gap-1"><span class="size-3 rounded {{ $cls }}"></span>{{ $name }} {{ $current['counts'][$code] }}</span>
                @endforeach
                <span>· {{ $current['unmarked'] }} not marked</span>
            </div>
        </div>
    @endif

    @if ($tab === 'payments' || $tab === 'overview')
        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
                <h3 class="mr-auto font-bold">{{ $tab === 'overview' ? 'Recent payments' : 'All payments' }}</h3>
                <button wire:click="newPayment('bonus')" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-violet-600 hover:bg-violet-50 dark:text-violet-400 dark:hover:bg-violet-500/10">+ Bonus</button>
                <button wire:click="newPayment('deduction')" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">+ Deduction</button>
                <button wire:click="newPayment('advance')" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-500/10">+ Advance</button>
                <button wire:click="newPayment('salary')" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10">+ Salary</button>
            </div>
            @forelse ($payments as $p)
                <div wire:key="pay-{{ $p->id }}" class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3 last:border-0 dark:border-slate-800">
                    <div class="w-16 shrink-0 text-xs text-slate-500">{{ $p->date->format('d M') }}<br>{{ $p->date->format('Y') }}</div>
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ $typeBadge[$p->type] }}">{{ $T[$p->type][0] }}</span>
                            @if ($p->for_month) <span class="font-normal text-slate-500">for {{ \Illuminate\Support\Carbon::parse($p->for_month.'-01')->format('M Y') }}</span> @endif
                        </p>
                        <p class="truncate text-xs text-slate-500">{{ $p->note ?: '' }}{{ $p->payment_mode ? ($p->note ? ' · ' : '').\App\Models\Expense::MODES[$p->payment_mode] : '' }}</p>
                    </div>
                    <p @class(['font-bold tabular-nums', 'text-rose-600 dark:text-rose-400' => $p->type === 'deduction'])>{{ $p->type === 'deduction' ? '−' : '' }}{{ money($p->amount) }}</p>
                    <div class="flex gap-1">
                        <button wire:click="editPayment({{ $p->id }})" class="rounded-lg px-2 py-1 text-xs font-semibold text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">Edit</button>
                        @can('delete-records')
                            <button wire:click="confirmDelete({{ $p->id }})" class="rounded-lg px-2 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">Delete</button>
                        @endcan
                    </div>
                </div>
            @empty
                <p class="px-5 py-10 text-center text-sm text-slate-500">No payments yet.</p>
            @endforelse
            @if ($tab === 'overview' && $payments->count() >= 5)
                <button wire:click="$set('tab', 'payments')" class="w-full border-t border-slate-100 py-3 text-sm font-semibold text-brand-600 hover:bg-slate-50 dark:border-slate-800 dark:text-brand-400 dark:hover:bg-slate-800/40">See all payments →</button>
            @endif
        </div>
    @endif

    {{-- Payment form --}}
    @if ($showPayForm)
        <x-modal :title="($payId ? 'Edit ' : 'New ').strtolower($T[$p_type][0] ?? 'payment').' · '.$s->name" close="cancelPay">
            <form wire:submit="savePayment" class="space-y-4">
                <div class="grid grid-cols-4 gap-1.5">
                    @foreach ($T as $k => [$label])
                        <button type="button" wire:click="$set('p_type', '{{ $k }}')" @class([
                            'rounded-xl border px-2 py-2 text-xs font-semibold transition sm:text-sm',
                            'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-400' => $p_type === $k,
                            'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => $p_type !== $k,
                        ])>{{ $label }}</button>
                    @endforeach
                </div>
                <p class="text-xs text-slate-500">
                    @switch($p_type)
                        @case('salary') Salary paid against earned work value. @break
                        @case('advance') Money given ahead; it is recovered from future salary automatically through the balance. @break
                        @case('bonus') Extra money on top of salary (festival, incentive). Added to earnings and paid out. @break
                        @case('deduction') Cuts earnings without any money changing hands (damage, food, fine). @break
                    @endswitch
                </p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="p-date">Date</label>
                        <input id="p-date" type="date" wire:model="p_date" max="{{ today()->toDateString() }}" class="input dark:[color-scheme:dark]">
                        @error('p_date') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="p-amt">Amount</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-400">{{ \App\Models\Setting::get('currency_symbol') }}</span>
                            <input id="p-amt" type="number" step="0.01" min="0" inputmode="decimal" wire:model="p_amount" autofocus class="input pl-7 tabular-nums">
                        </div>
                        @error('p_amount') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                @if ($p_type === 'salary')
                    <div>
                        <label class="label" for="p-for">Salary for month</label>
                        <input id="p-for" type="month" wire:model="p_for_month" max="{{ now()->format('Y-m') }}" class="input dark:[color-scheme:dark]">
                        @error('p_for_month') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif
                @if ($p_type !== 'deduction')
                    <div>
                        <span class="label">Paid via</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach (\App\Models\Expense::MODES as $k => $label)
                                <button type="button" wire:click="$set('p_mode', '{{ $k }}')" @class([
                                    'rounded-full px-3 py-1 text-xs font-semibold ring-1 transition',
                                    'bg-brand-500 text-white ring-brand-500' => $p_mode === $k,
                                    'text-slate-600 ring-slate-200 hover:bg-slate-50 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800' => $p_mode !== $k,
                                ])>{{ $label }}</button>
                            @endforeach
                        </div>
                        @error('p_mode') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label class="label" for="p-note">Note <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="p-note" wire:model="p_note" class="input" placeholder="{{ $p_type === 'deduction' ? 'e.g. Broken plates' : 'e.g. Diwali' }}">
                    @error('p_note') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" wire:click="cancelPay" class="btn-ghost">Cancel</button>
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="savePayment">{{ $payId ? 'Save changes' : 'Save' }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($confirmingDelete)
        <x-modal title="Delete entry?" size="max-w-sm" close="cancelPay">
            <p class="text-sm text-slate-600 dark:text-slate-300">This removes the payment and recalculates the balance. It can't be undone.</p>
            <div class="mt-5 flex justify-end gap-2">
                <button wire:click="cancelPay" class="btn-ghost">Cancel</button>
                <button wire:click="deletePayment" class="btn bg-rose-600 text-white hover:bg-rose-700">Delete</button>
            </div>
        </x-modal>
    @endif

    @if ($showShare)
        <x-modal title="Share with {{ explode(' ', $s->name)[0] }}" close="closeShare">
            @if ($shareUrl)
                <p class="text-sm text-slate-600 dark:text-slate-300">Anyone with this link can see {{ explode(' ', $s->name)[0] }}'s attendance, payments and balance — read-only, no login. Nothing else in your restaurant is visible.</p>
                <div x-data="{ copied: false }" class="mt-4 flex gap-2">
                    <input readonly value="{{ $shareUrl }}" class="input font-mono text-xs" @focus="$el.select()">
                    <button type="button" class="btn-ghost shrink-0"
                            @click="navigator.clipboard?.writeText(@js($shareUrl)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                            x-text="copied ? 'Copied ✓' : 'Copy'">Copy</button>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($s->mobile)
                        <a target="_blank" rel="noopener" class="btn bg-emerald-600 text-white hover:bg-emerald-700"
                           href="https://wa.me/91{{ $s->mobile }}?text={{ rawurlencode('Namaste '.explode(' ', $s->name)[0].', you can check your attendance and salary here: '.$shareUrl.($hasPin ? ' (PIN required — ask the owner)' : '')) }}">Send on WhatsApp</a>
                    @endif
                    <a href="{{ $shareUrl }}" target="_blank" rel="noopener" class="btn-ghost">Open</a>
                </div>

                <div class="mt-5 rounded-2xl border border-slate-200 p-4 dark:border-slate-800">
                    <p class="text-sm font-bold">PIN protection <span class="font-normal text-slate-500">· {{ $hasPin ? 'On' : 'Off' }}</span></p>
                    <p class="mt-1 text-xs text-slate-500">With a PIN, the link only opens after entering 4 digits — safer if the link is forwarded.</p>
                    <form wire:submit="savePin" class="mt-3 flex gap-2">
                        <input wire:model="sharePin" inputmode="numeric" maxlength="4" autocomplete="off" class="input w-28 text-center font-mono tracking-[0.4em]" placeholder="••••">
                        <button class="btn-ghost">{{ $hasPin ? 'Change PIN' : 'Set PIN' }}</button>
                        @if ($hasPin) <button type="button" wire:click="removePin" class="text-sm font-semibold text-rose-600 hover:underline">Remove</button> @endif
                    </form>
                    @error('sharePin') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div class="mt-5 flex flex-wrap justify-between gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <button wire:click="regenerateShareLink" wire:confirm="Make a new link? The current link will stop working." class="text-sm font-semibold text-slate-600 hover:underline dark:text-slate-300">New link</button>
                    <button wire:click="revokeShareLink" wire:confirm="Turn off sharing? The link will stop working." class="text-sm font-semibold text-rose-600 hover:underline">Turn off sharing</button>
                </div>
            @else
                <div class="py-4 text-center">
                    <div class="mx-auto grid size-14 place-items-center rounded-2xl bg-sky-50 text-sky-500 dark:bg-sky-500/10"><x-icon name="cloud" class="size-7" /></div>
                    <p class="mt-3 font-semibold">Give {{ explode(' ', $s->name)[0] }} a private link</p>
                    <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">They can check their attendance, salary, advances and balance on their phone — no login, read-only. You can add a PIN or turn it off any time.</p>
                    <button wire:click="createShareLink" class="btn-primary mt-5">Create share link</button>
                </div>
            @endif
        </x-modal>
    @endif

    @if ($showStaffForm)
        <x-staff-form :staff-id="$staffId" :sf="$sf" :staff-photo="$staffPhoto" />
    @endif
</div>
