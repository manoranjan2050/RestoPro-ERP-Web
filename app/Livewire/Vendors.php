<?php

namespace App\Livewire;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Vendors')]
class Vendors extends Component
{
    #[Url(as: 'q')]
    public string $search = '';
    #[Url(as: 'show')]
    public string $show = 'active'; // active | due | all

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $mobile = '';
    public string $address = '';
    public string $default_category_id = '';
    public $opening_balance = '';
    public string $note = '';
    public bool $is_active = true;

    public ?int $confirmingDelete = null;

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $v = Vendor::findOrFail($id);
        $this->resetForm();
        $this->editingId = $v->id;
        $this->name = $v->name;
        $this->mobile = (string) $v->mobile;
        $this->address = (string) $v->address;
        $this->default_category_id = (string) ($v->default_category_id ?? '');
        $this->opening_balance = (float) $v->opening_balance == 0 ? '' : rtrim(rtrim(number_format((float) $v->opening_balance, 2, '.', ''), '0'), '.');
        $this->note = (string) $v->note;
        $this->is_active = $v->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->name = trim($this->name);
        $this->mobile = (string) User::normaliseMobile($this->mobile);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:80', new \App\Rules\UniqueCi('vendors', 'name', $this->editingId)],
            'mobile' => ['nullable', 'digits:10'],
            'address' => ['nullable', 'string', 'max:255'],
            'default_category_id' => ['nullable', Rule::exists('expense_categories', 'id')],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ], [], ['default_category_id' => 'usual category']);

        $payload = [
            'name' => $data['name'],
            'mobile' => $data['mobile'] ?: null,
            'address' => $data['address'] ?: null,
            'default_category_id' => $data['default_category_id'] ?: null,
            'opening_balance' => (float) ($data['opening_balance'] ?: 0),
            'note' => $data['note'] ?: null,
            'is_active' => $data['is_active'],
        ];

        if ($this->editingId) {
            Vendor::findOrFail($this->editingId)->update($payload);
        } else {
            Vendor::create($payload);
        }

        $this->dispatch('toast', message: $this->editingId ? 'Vendor updated' : 'Vendor added');
        $this->showForm = false;
        $this->resetForm();
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('delete-records');
        $this->confirmingDelete = $id;
    }

    public function delete(): void
    {
        Gate::authorize('delete-records');
        $v = Vendor::withCount(['expenses', 'payments'])->findOrFail($this->confirmingDelete);
        $this->confirmingDelete = null;

        if ($v->expenses_count + $v->payments_count > 0) {
            $v->update(['is_active' => false]);
            $this->dispatch('toast', message: "{$v->name} has bills, so it was deactivated instead of deleted");

            return;
        }
        $v->delete();
        $this->dispatch('toast', message: 'Vendor deleted');
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->confirmingDelete = null;
        $this->resetForm();
    }

    public function render()
    {
        $vendors = Vendor::query()->select('vendors.*')->withDue()->with('defaultCategory')
            ->withCount('expenses')
            ->addSelect(['last_bill' => Expense::select('date')->whereColumn('expenses.vendor_id', 'vendors.id')->orderByDesc('date')->limit(1)])
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.trim($this->search).'%')
                ->orWhere('mobile', 'like', '%'.trim($this->search).'%')))
            ->when($this->show === 'active', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->when($this->show === 'due', fn ($c) => $c->filter(fn ($v) => $v->due() > 0)->values());

        $all = Vendor::query()->select('vendors.*')->withDue()->get();

        return view('livewire.vendors', [
            'vendors' => $vendors,
            'totalDue' => $all->sum(fn ($v) => max($v->due(), 0)),
            'withDue' => $all->filter(fn ($v) => $v->due() > 0)->count(),
            'activeCount' => $all->where('is_active', true)->count(),
            'categories' => ExpenseCategory::ordered()->where('is_active', true)->get(),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'mobile', 'address', 'default_category_id', 'opening_balance', 'note', 'is_active');
        $this->resetValidation();
    }
}
