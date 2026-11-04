<?php

namespace App\Livewire\Sales;

use App\Models\DiningTable;
use App\Models\Order;
use App\Services\Billing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Floor view: free / running tables. Tap a table to open its bill on the POS. Owner/manager manage the list. */
#[Title('Tables')]
class Tables extends Component
{
    public bool $manage = false;

    public bool $showForm = false;
    public ?int $editingId = null;
    public array $t = ['name' => '', 'area' => '', 'seats' => '4'];

    // quick add T1…T10
    public bool $showBulk = false;
    public array $bulk = ['prefix' => 'T', 'from' => '1', 'to' => '10', 'area' => '', 'seats' => '4'];

    public ?int $moving = null;   // order id being moved
    public string $moveTo = '';

    public function mount(): void
    {
        abort_unless(\App\Support\Sales::on('tables'), 404);
    }

    public function create(): void
    {
        Gate::authorize('back-office');
        $this->reset('editingId');
        $this->t = ['name' => '', 'area' => '', 'seats' => '4'];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        Gate::authorize('back-office');
        $x = DiningTable::findOrFail($id);
        $this->editingId = $x->id;
        $this->t = ['name' => $x->name, 'area' => (string) $x->area, 'seats' => (string) $x->seats];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        Gate::authorize('back-office');
        $this->t = array_map(fn ($v) => trim((string) $v), $this->t);
        $area = $this->t['area'] !== '' ? $this->t['area'] : null;
        $this->validate([
            't.name' => ['required', 'string', 'max:30', Rule::unique('dining_tables', 'name')->where(fn ($q) => $area === null ? $q->whereNull('area') : $q->where('area', $area))->ignore($this->editingId)],
            't.area' => ['nullable', 'string', 'max:30'],
            't.seats' => ['required', 'integer', 'between:1,50'],
        ], ['t.name.unique' => 'There is already a table with this name here.'], ['t.name' => 'table name', 't.seats' => 'seats']);
        $data = ['name' => $this->t['name'], 'area' => $area, 'seats' => (int) $this->t['seats']];
        $this->editingId ? DiningTable::whereKey($this->editingId)->update($data) : DiningTable::create($data + ['sort' => (int) DiningTable::max('sort') + 1]);
        $this->showForm = false;
        $this->dispatch('toast', message: 'Table saved');
    }

    public function saveBulk(): void
    {
        Gate::authorize('back-office');
        $this->validate([
            'bulk.prefix' => ['nullable', 'string', 'max:10'],
            'bulk.from' => ['required', 'integer', 'between:0,999'],
            'bulk.to' => ['required', 'integer', 'gte:bulk.from', 'lte:999'],
            'bulk.area' => ['nullable', 'string', 'max:30'],
            'bulk.seats' => ['required', 'integer', 'between:1,50'],
        ], [], ['bulk.to' => 'last number']);
        if ((int) $this->bulk['to'] - (int) $this->bulk['from'] > 99) {
            $this->addError('bulk.to', 'Up to 100 tables at a time.');

            return;
        }
        $area = trim((string) $this->bulk['area']) ?: null;
        $made = 0;
        $sort = (int) DiningTable::max('sort');
        for ($n = (int) $this->bulk['from']; $n <= (int) $this->bulk['to']; $n++) {
            $name = trim((string) $this->bulk['prefix']).$n;
            $exists = DiningTable::where('name', $name)->when($area === null, fn ($q) => $q->whereNull('area'), fn ($q) => $q->where('area', $area))->exists();
            if (! $exists) {
                DiningTable::create(['name' => $name, 'area' => $area, 'seats' => (int) $this->bulk['seats'], 'sort' => ++$sort]);
                $made++;
            }
        }
        $this->showBulk = false;
        $this->dispatch('toast', message: "{$made} table(s) added");
    }

    public function toggle(int $id): void
    {
        Gate::authorize('back-office');
        $x = DiningTable::findOrFail($id);
        $x->update(['is_active' => ! $x->is_active]);
    }

    public function delete(int $id): void
    {
        Gate::authorize('delete-records');
        $x = DiningTable::with('openOrder')->findOrFail($id);
        if ($x->openOrder) {
            $this->dispatch('toast', message: "{$x->name} has a running bill — settle it first");

            return;
        }
        $x->delete();
    }

    public function startMove(int $orderId): void
    {
        $this->moving = $orderId;
        $this->moveTo = '';
        $this->resetValidation();
    }

    public function closeMove(): void
    {
        $this->moving = null;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->showBulk = false;
    }

    public function move(): void
    {
        $order = Order::where('status', 'open')->where('type', 'dine_in')->findOrFail($this->moving);
        try {
            Billing::moveTable($order, (int) $this->moveTo);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }
        $this->moving = null;
        $this->dispatch('toast', message: 'Bill moved to '.$order->fresh()->table?->name);
    }

    public function render()
    {
        $tables = DiningTable::query()->when(! $this->manage, fn ($q) => $q->where('is_active', true))
            ->with(['openOrder' => fn ($q) => $q->withCount('items')])
            ->orderBy('area')->orderBy('sort')->orderBy('name')->get();

        return view('livewire.sales.tables', [
            'areas' => $tables->groupBy(fn ($t) => $t->area ?: ''),
            'busy' => $tables->filter(fn ($t) => $t->openOrder)->count(),
            'running' => (float) $tables->sum(fn ($t) => $t->openOrder?->total ?? 0),
            'free' => DiningTable::where('is_active', true)->whereDoesntHave('openOrder')->orderBy('name')->get(),
            'canManage' => Gate::allows('back-office'),
        ]);
    }
}
