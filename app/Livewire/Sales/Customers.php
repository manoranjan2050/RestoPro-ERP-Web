<?php

namespace App\Livewire\Sales;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** v2 customer book: looked up by mobile at the counter; birthdays/anniversaries for greetings. */
#[Title('Customers')]
class Customers extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showForm = false;
    public ?int $editingId = null;
    public array $c = [];
    public ?int $confirmingDelete = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        $digits = preg_replace('/\D/', '', $this->search);
        if (strlen($digits) === 10) {
            $this->c['mobile'] = $digits;
        } elseif ($this->search !== '' && $digits === '') {
            $this->c['name'] = trim($this->search);
        }
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $x = Customer::findOrFail($id);
        $this->resetForm();
        $this->editingId = $x->id;
        $this->c = [
            'name' => $x->name, 'mobile' => (string) $x->mobile, 'email' => (string) $x->email, 'address' => (string) $x->address,
            'birthday' => $x->birthday?->toDateString() ?? '', 'anniversary' => $x->anniversary?->toDateString() ?? '',
            'gstin' => (string) $x->gstin, 'note' => (string) $x->note,
        ];
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->c = array_map(fn ($v) => trim((string) $v), $this->c);
        $this->c['mobile'] = (string) User::normaliseMobile($this->c['mobile'] ?: null);
        $this->c['gstin'] = mb_strtoupper($this->c['gstin']);
        $this->validate([
            'c.name' => ['required', 'string', 'max:80'],
            'c.mobile' => ['nullable', 'digits:10', Rule::unique('customers', 'mobile')->ignore($this->editingId)],
            'c.email' => ['nullable', 'email', 'max:120'],
            'c.address' => ['nullable', 'string', 'max:255'],
            'c.birthday' => ['nullable', 'date', 'before:tomorrow'],
            'c.anniversary' => ['nullable', 'date', 'before:tomorrow'],
            'c.gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z0-9]{13}$/'],
            'c.note' => ['nullable', 'string', 'max:255'],
        ], ['c.mobile.unique' => 'A customer with this mobile already exists.', 'c.gstin.regex' => 'GSTIN is 15 characters.'],
            ['c.name' => 'name', 'c.mobile' => 'mobile', 'c.email' => 'email']);

        $data = array_map(fn ($v) => $v === '' ? null : $v, $this->c);
        $this->editingId ? Customer::findOrFail($this->editingId)->update($data) : Customer::create($data);
        $this->showForm = false;
        $this->dispatch('toast', message: $this->editingId ? 'Customer updated' : 'Customer added');
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
        Customer::whereKey($this->confirmingDelete)->delete();
        $this->confirmingDelete = null;
        $this->dispatch('toast', message: 'Customer deleted');
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->confirmingDelete = null;
        $this->resetForm();
    }

    public function render()
    {
        return view('livewire.sales.customers', [
            'customers' => Customer::search($this->search)->orderBy('name')->paginate(30),
            'total' => Customer::count(),
            'upcoming' => $this->upcoming(),
        ]);
    }

    /** Birthdays and anniversaries in the next 7 days (year ignored). */
    private function upcoming(): array
    {
        $out = [];
        $today = today();
        foreach (Customer::whereNotNull('birthday')->orWhereNotNull('anniversary')->get() as $x) {
            foreach (['birthday' => '🎂 Birthday', 'anniversary' => '💍 Anniversary'] as $col => $label) {
                if (! $x->$col) {
                    continue;
                }
                $next = Carbon::create($today->year, $x->$col->month, min($x->$col->day, Carbon::create($today->year, $x->$col->month)->daysInMonth));
                if ($next->lt($today)) {
                    $next->addYear();
                }
                $days = (int) $today->diffInDays($next);
                if ($days <= 7) {
                    $out[] = ['customer' => $x, 'label' => $label, 'days' => $days, 'date' => $next];
                }
            }
        }
        usort($out, fn ($a, $b) => $a['days'] <=> $b['days']);

        return $out;
    }

    private function resetForm(): void
    {
        $this->reset('editingId');
        $this->c = ['name' => '', 'mobile' => '', 'email' => '', 'address' => '', 'birthday' => '', 'anniversary' => '', 'gstin' => '', 'note' => ''];
        $this->resetValidation();
    }
}
