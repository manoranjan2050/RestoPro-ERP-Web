<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Services\RevenueReport;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Revenue Log')]
class RevenueLog extends Component
{
    #[Url]
    public string $preset = 'month';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $group = 'day';

    public function mount(): void
    {
        if (! array_key_exists($this->preset, RevenueReport::PRESETS)) {
            $this->preset = 'month';
        }
        if (! in_array($this->group, ['day', 'week', 'month'], true)) {
            $this->group = 'day';
        }
        if ($this->preset !== 'custom' || ! $this->validDates()) {
            $this->applyPreset();
        }
    }

    public function updatedPreset(): void
    {
        if ($this->preset !== 'custom') {
            $this->applyPreset();
        }
    }

    public function updatedFrom(): void
    {
        $this->preset = 'custom';
    }

    public function updatedTo(): void
    {
        $this->preset = 'custom';
    }

    public function report(): RevenueReport
    {
        if (! $this->validDates()) {
            $this->applyPreset();
        }
        $from = Carbon::parse($this->from);
        $to = Carbon::parse($this->to);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return new RevenueReport($from->startOfDay(), $to->startOfDay(), $this->group);
    }

    public function render()
    {
        $report = $this->report();
        $totals = $report->totals();
        $rows = $report->rows();
        $series = $report->dailySeries();
        $best = $rows->sortByDesc('total')->first();

        return view('livewire.revenue-log', [
            'report' => $report,
            'totals' => $totals,
            'rows' => $rows,
            'series' => $series,
            'best' => $best,
            'avg' => $totals['days'] ? $totals['total'] / $totals['days'] : 0,
            'presets' => RevenueReport::PRESETS,
        ]);
    }

    private function applyPreset(): void
    {
        [$f, $t] = RevenueReport::presetRange($this->preset, (int) Setting::get('fy_start_month', '4'));
        $this->from = $f->toDateString();
        $this->to = $t->toDateString();
    }

    private function validDates(): bool
    {
        return (bool) (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to)
            && strtotime($this->from) && strtotime($this->to));
    }
}
