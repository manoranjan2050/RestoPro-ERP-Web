<?php

namespace App\Livewire;

use App\Models\CashCollection;
use App\Services\DaySales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Cash Collection')]
class Collections extends Component
{
    #[Url(as: 'm')]
    public string $month = '';

    // Form state
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $date = '';
    public $cash = '';
    public $upi = '';
    public $online = '';
    public string $note = '';
    public ?int $duplicateId = null;
    /** Bill (POS) share of the day being edited — read-only here. */
    public array $pos = ['cash' => 0, 'upi' => 0, 'online' => 0];

    public ?int $confirmingDelete = null;

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->format('Y-m');
        }
    }

    public function updatedMonth(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month) || $this->month > now()->format('Y-m')) {
            $this->month = now()->format('Y-m');
        }
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = $this->monthStart()->addMonthsNoOverflow($delta)->format('Y-m');
    }

    public function create(?string $date = null): void
    {
        $this->resetForm();
        $this->date = $date ?? now()->toDateString();
        $this->showForm = true;
        $this->checkDuplicate();
    }

    public function edit(int $id): void
    {
        $c = CashCollection::findOrFail($id);
        $this->resetForm();
        $this->editingId = $c->id;
        $this->date = $c->date->toDateString();
        $manual = DaySales::manual($c);
        $this->pos = DaySales::pos($c);
        $this->cash = $this->plain($manual['cash']);
        $this->upi = $this->plain($manual['upi']);
        $this->online = $this->plain($manual['online']);
        $this->note = (string) $c->note;
        $this->showForm = true;
    }

    public function updatedDate(): void
    {
        $this->checkDuplicate();
    }

    public function save(): void
    {
        foreach (['cash', 'upi', 'online'] as $f) {
            $this->$f = $this->$f === '' || $this->$f === null ? 0 : $this->$f;
        }

        $data = $this->validate([
            'date' => ['required', 'date', 'before_or_equal:today',
                Rule::unique('cash_collections', 'date')->ignore($this->editingId)],
            'cash' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'upi' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'online' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'date.unique' => 'An entry for this date already exists.',
            'date.before_or_equal' => 'Date cannot be in the future.',
        ]);

        $data['date'] = Carbon::parse($data['date'])->toDateString();
        $data['note'] = $data['note'] ?: null;
        $data['updated_by'] = Auth::id();

        if ($this->editingId) {
            // The form edits only the hand-entered share; bill payments stay on top.
            $row = CashCollection::findOrFail($this->editingId);
            DaySales::fillManual($row->fill(collect($data)->except(['cash', 'upi', 'online'])->all()), $data)->save();
            $msg = 'Collection updated';
        } else {
            CashCollection::create($data + ['created_by' => Auth::id()]);
            $msg = 'Collection added';
        }

        $this->month = substr($data['date'], 0, 7);
        $this->showForm = false;
        $this->resetForm();
        $this->dispatch('toast', message: $msg);
    }

    public function editDuplicate(): void
    {
        if ($this->duplicateId) {
            $this->edit($this->duplicateId);
        }
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('delete-records');
        $this->confirmingDelete = $id;
    }

    public function delete(): void
    {
        Gate::authorize('delete-records');
        if ($row = CashCollection::find($this->confirmingDelete)) {
            DaySales::deleteManual($row);
        }
        $this->confirmingDelete = null;
        $this->dispatch('toast', message: 'Entry deleted');
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->confirmingDelete = null;
        $this->resetForm();
    }

    public function render()
    {
        $start = $this->monthStart();
        $end = $start->copy()->endOfMonth();
        $query = CashCollection::between($start, $end);

        $entries = (clone $query)->orderByDesc('date')->get();
        $totals = CashCollection::totals($query);

        // Days of this month (up to today) that have no entry yet.
        $last = $end->isFuture() ? today() : $end;
        $have = $entries->map(fn ($e) => $e->date->toDateString())->flip();
        $missing = [];
        for ($d = $start->copy(); $d->lte($last); $d->addDay()) {
            if (! $have->has($d->toDateString())) {
                $missing[] = $d->copy();
            }
        }

        $best = $entries->sortByDesc(fn ($e) => (float) $e->total)->first();

        return view('livewire.collections', [
            'entries' => $entries,
            'totals' => $totals,
            'avg' => $totals['days'] ? $totals['total'] / $totals['days'] : 0,
            'best' => $best,
            'missing' => array_reverse($missing),
            'monthLabel' => $start->format('F Y'),
            'isCurrentMonth' => $start->isSameMonth(now()),
            'formTotal' => (float) $this->cash + (float) $this->upi + (float) $this->online + array_sum($this->pos),
            'posTotal' => array_sum($this->pos),
        ]);
    }

    private function monthStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }

    private function checkDuplicate(): void
    {
        $this->duplicateId = null;
        if ($this->date && ! $this->editingId) {
            try {
                $this->duplicateId = CashCollection::whereDate('date', Carbon::parse($this->date)->toDateString())->value('id');
            } catch (\Throwable) {
                $this->duplicateId = null;
            }
        }
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'date', 'cash', 'upi', 'online', 'note', 'duplicateId', 'pos');
        $this->resetValidation();
    }

    private function plain($v): string
    {
        $v = (float) $v;

        return $v == 0 ? '' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
