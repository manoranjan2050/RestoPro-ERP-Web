<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Services\ReportBuilder;
use App\Services\RevenueReport;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Reports')]
class Reports extends Component
{
    #[Url]
    public string $type = 'monthly';
    #[Url(as: 'm')]
    public string $month = '';
    #[Url]
    public ?int $fy = null;
    #[Url]
    public string $from = '';
    #[Url]
    public string $to = '';
    #[Url(as: 'd')]
    public string $date = '';

    public function mount(): void
    {
        $this->sanitise();
    }

    public function updated(): void
    {
        $this->sanitise();
    }

    public function shiftDay(int $d): void
    {
        $next = Carbon::parse($this->date)->addDays($d);
        $this->date = $next->isFuture() ? today()->toDateString() : $next->toDateString();
    }

    public function shiftMonth(int $d): void
    {
        $this->month = Carbon::parse($this->month.'-01')->addMonthsNoOverflow($d)->format('Y-m');
        $this->sanitise();
    }

    public function range(string $preset): void
    {
        [$f, $t] = RevenueReport::presetRange($preset, $this->fyStartMonth());
        $this->from = $f->toDateString();
        $this->to = $t->toDateString();
    }

    /** Parameters for the current report (also used for the PDF/Excel links). */
    public function params(): array
    {
        return match (ReportBuilder::types()[$this->type][2]) {
            'month' => ['month' => $this->month],
            'fy' => ['fy' => $this->fy],
            'range' => ['from' => min($this->from, $this->to), 'to' => max($this->from, $this->to)],
            'day' => ['date' => $this->date],
            default => [],
        };
    }

    public function render()
    {
        $params = $this->params();

        return view('livewire.reports', [
            'report' => ReportBuilder::build($this->type, $params),
            'params' => $params,
            'types' => ReportBuilder::types(),
            'years' => $this->years(),
        ]);
    }

    private function years(): array
    {
        $sm = $this->fyStartMonth();
        $current = RevenueReport::fyStart(today(), $sm)->year;
        $out = [];
        for ($y = $current; $y >= $current - 5; $y--) {
            $out[$y] = $sm === 1 ? "Year {$y}" : 'FY '.$y.'-'.substr((string) ($y + 1), -2);
        }

        return $out;
    }

    private function fyStartMonth(): int
    {
        return (int) Setting::get('fy_start_month', '4');
    }

    private function sanitise(): void
    {
        if (! array_key_exists($this->type, ReportBuilder::types())) {
            $this->type = 'monthly';
        }
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month) || $this->month > now()->format('Y-m')) {
            $this->month = now()->format('Y-m');
        }
        $years = $this->years();
        if (! $this->fy || ! isset($years[$this->fy])) {
            $this->fy = array_key_first($years);
        }
        $ok = fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
        if (! $ok($this->date) || $this->date > today()->toDateString()) {
            $this->date = today()->toDateString();
        }
        if (! $ok($this->from) || ! $ok($this->to)) {
            $this->range('month');
        }
    }
}
