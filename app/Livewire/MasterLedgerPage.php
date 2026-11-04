<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Services\MasterLedger;
use App\Services\RevenueReport;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Master Ledger')]
class MasterLedgerPage extends Component
{
    #[Url]
    public string $preset = 'month';
    #[Url]
    public string $from = '';
    #[Url]
    public string $to = '';
    #[Url]
    public string $source = '';
    #[Url]
    public string $account = '';
    #[Url(as: 'dir')]
    public string $direction = '';
    #[Url(as: 'q')]
    public string $search = '';

    public function mount(): void
    {
        if (! array_key_exists($this->preset, RevenueReport::PRESETS)) {
            $this->preset = 'month';
        }
        if ($this->preset !== 'custom' || ! $this->validDates()) {
            $this->applyPreset();
        }
        $this->sanitise();
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

    public function updated(): void
    {
        $this->sanitise();
    }

    public function clearFilters(): void
    {
        $this->reset('source', 'account', 'direction', 'search');
    }

    /** Filters as passed to the service (also used by the CSV export link). */
    public function filters(): array
    {
        if (! $this->validDates()) {
            $this->applyPreset();
        }
        [$from, $to] = $this->from <= $this->to ? [$this->from, $this->to] : [$this->to, $this->from];

        return array_filter([
            'from' => $from, 'to' => $to, 'source' => $this->source, 'account' => $this->account, 'direction' => $this->direction,
        ]);
    }

    public function render()
    {
        $f = $this->filters();
        // Opening = everything before the range with the same account/source filter (direction filter would distort it).
        $opening = MasterLedger::balanceBefore($f['from'], array_diff_key($f, ['direction' => 1]));
        $rows = MasterLedger::rows($f, $opening);

        if (trim($this->search) !== '') {
            $term = mb_strtolower(trim($this->search));
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['title'].' '.$r['sub']), $term))->values();
        }

        return view('livewire.master-ledger', [
            'rows' => $rows->reverse()->values(), // newest first
            'opening' => $opening,
            'closing' => $opening + $rows->sum('in') - $rows->sum('out'),
            'summary' => MasterLedger::summary($f),
            'balances' => MasterLedger::accountBalances($f['to']),
            'presets' => RevenueReport::PRESETS,
            'filtersOn' => $this->source || $this->account || $this->direction || trim($this->search) !== '',
            'f' => $f,
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

    private function sanitise(): void
    {
        if (! array_key_exists($this->source, MasterLedger::SOURCES)) {
            $this->source = '';
        }
        if (! array_key_exists($this->account, MasterLedger::ACCOUNTS)) {
            $this->account = '';
        }
        if (! in_array($this->direction, ['in', 'out'], true)) {
            $this->direction = '';
        }
    }
}
