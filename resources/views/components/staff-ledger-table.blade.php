{{-- Month-by-month staff ledger with carry-forward. Shared by the staff profile and the public share page. --}}
@props(['rows', 'totals', 'label' => ''])
@php
    $bal = fn ($v) => $v > 0 ? 'text-rose-600 dark:text-rose-400' : ($v < 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400');
@endphp
<div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <x-stat label="Full year work value" :value="money($totals['work_value'] ?? 0)" icon="spark" tone="violet" :hint="$label.' · '.($totals['present'] ?? 0).' present · '.($totals['absent'] ?? 0).' absent'" />
    <x-stat label="Full year total paid" :value="money(($totals['salary'] ?? 0) + ($totals['advance'] ?? 0))" icon="cash" tone="emerald" :hint="'Salary '.money($totals['salary'] ?? 0).' · advance '.money($totals['advance'] ?? 0)" />
    <x-stat label="Bonus / deductions" :value="money($totals['bonus'] ?? 0)" icon="trending" tone="sky" :hint="'Deductions '.money($totals['deductions'] ?? 0)" />
    <x-stat label="Carried forward" :value="money(abs($totals['closing'] ?? 0))" icon="book" :tone="($totals['closing'] ?? 0) < 0 ? 'amber' : 'rose'"
            :hint="($totals['closing'] ?? 0) > 0 ? 'Still to be paid' : (($totals['closing'] ?? 0) < 0 ? 'Advance to recover' : 'Fully settled')" />
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-slate-800/50">
                <tr>
                    <th class="px-4 py-3 text-left">Month</th>
                    <th class="px-3 py-3 text-center">P / H / A</th>
                    <th class="px-3 py-3 text-right">Opening</th>
                    <th class="px-3 py-3 text-right">Work value</th>
                    <th class="px-3 py-3 text-right">Bonus − Ded.</th>
                    <th class="px-3 py-3 text-right">Salary paid</th>
                    <th class="px-3 py-3 text-right">Advance</th>
                    <th class="px-4 py-3 text-right">Closing</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($rows as $r)
                    <tr class="hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                        <td class="px-4 py-2.5">
                            <p class="font-semibold">{{ $r['label'] }}</p>
                            @if ($r['in_progress']) <p class="text-[11px] text-sky-600 dark:text-sky-400">in progress</p> @endif
                        </td>
                        <td class="px-3 py-2.5 text-center text-xs tabular-nums">
                            <span class="text-emerald-600">{{ $r['counts']['P'] }}</span> /
                            <span class="text-amber-600">{{ $r['counts']['H'] }}</span> /
                            <span class="text-rose-600">{{ $r['counts']['A'] }}</span>
                        </td>
                        <td class="px-3 py-2.5 text-right tabular-nums {{ $bal($r['opening']) }}">{{ money($r['opening']) }}</td>
                        <td class="px-3 py-2.5 text-right font-medium tabular-nums">{{ money($r['work_value']) }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums text-slate-500">{{ $r['bonus'] || $r['deductions'] ? money($r['bonus'] - $r['deductions']) : '—' }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $r['salary_paid'] ? money($r['salary_paid']) : '—' }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ $r['advance_paid'] ? money($r['advance_paid']) : '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-bold tabular-nums {{ $bal($r['closing']) }}">{{ money($r['closing']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-slate-500">No months in this year.</td></tr>
                @endforelse
            </tbody>
            @if (count($rows))
                <tfoot class="bg-slate-50 font-bold dark:bg-slate-800/50">
                    <tr>
                        <td class="px-4 py-3">Full year</td>
                        <td class="px-3 py-3 text-center text-xs tabular-nums">{{ $totals['present'] }} / {{ $totals['half'] }} / {{ $totals['absent'] }}</td>
                        <td class="px-3 py-3 text-right tabular-nums {{ $bal($totals['opening']) }}">{{ money($totals['opening']) }}</td>
                        <td class="px-3 py-3 text-right tabular-nums">{{ money($totals['work_value']) }}</td>
                        <td class="px-3 py-3 text-right tabular-nums">{{ money($totals['bonus'] - $totals['deductions']) }}</td>
                        <td class="px-3 py-3 text-right tabular-nums">{{ money($totals['salary']) }}</td>
                        <td class="px-3 py-3 text-right tabular-nums">{{ money($totals['advance']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums {{ $bal($totals['closing']) }}">{{ money($totals['closing']) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <p class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500 dark:border-slate-800">
        Closing = opening + work value + bonus − deductions − salary − advance − bonus paid. It carries into the next month.
        <span class="text-rose-600 dark:text-rose-400">Red</span> = to be paid to staff, <span class="text-amber-600 dark:text-amber-400">amber</span> = advance to recover.
    </p>
</div>
