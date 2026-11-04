<?php

namespace App\Livewire;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Expenses')]
class Expenses extends Component
{
    use WithFileUploads;

    // Filters
    #[Url(as: 'm')]
    public string $month = '';
    #[Url(as: 'cat')]
    public string $filterCategory = '';
    #[Url(as: 'v')]
    public string $filterVendor = '';
    #[Url(as: 's')]
    public string $filterStatus = '';
    #[Url(as: 'q')]
    public string $search = '';

    // Form
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $date = '';
    public string $category_id = '';
    public string $new_category = '';
    public string $vendor_id = '';
    public string $new_vendor = '';
    public $amount = '';
    public string $status = 'paid';      // paid | unpaid | partial (partial only when editing one)
    public string $payment_mode = 'cash';
    public string $note = '';
    public $bill;
    public ?string $existingBill = null;
    public bool $removeBill = false;
    public float $khataPaid = 0;

    public ?int $confirmingDelete = null;

    public function mount(): void
    {
        $this->normaliseMonth();
    }

    public function updatedMonth(): void
    {
        $this->normaliseMonth();
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = $this->monthStart()->addMonthsNoOverflow($delta)->format('Y-m');
    }

    /** Re-render after the category manager closes so dropdowns pick up changes. */
    #[\Livewire\Attributes\On('categories-changed')]
    public function refreshCategories(): void {}

    public function clearFilters(): void
    {
        $this->reset('filterCategory', 'filterVendor', 'filterStatus', 'search');
    }

    public function create(): void
    {
        $this->resetForm();
        $this->date = $this->monthStart()->isSameMonth(now()) ? today()->toDateString() : $this->monthStart()->endOfMonth()->toDateString();
        $this->category_id = $this->filterCategory;
        $this->vendor_id = $this->filterVendor;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $e = Expense::findOrFail($id);
        $this->resetForm();
        $this->editingId = $e->id;
        $this->date = $e->date->toDateString();
        $this->category_id = (string) $e->category_id;
        $this->vendor_id = (string) ($e->vendor_id ?? '');
        $this->amount = rtrim(rtrim(number_format((float) $e->amount, 2, '.', ''), '0'), '.');
        // The form edits what was paid on the bill itself; khata payments are managed on the vendor page.
        $direct = (float) $e->direct_paid;
        $this->status = $direct >= (float) $e->amount ? 'paid' : ($direct > 0 ? 'partial' : 'unpaid');
        $this->khataPaid = $e->khataPaid();
        $this->payment_mode = $e->payment_mode ?? 'cash';
        $this->note = (string) $e->note;
        $this->existingBill = $e->bill_path;
        $this->showForm = true;
    }

    /** Picking a vendor pre-selects their usual category. */
    public function updatedVendorId(): void
    {
        if ($this->vendor_id && is_numeric($this->vendor_id) && ! $this->category_id) {
            $this->category_id = (string) (Vendor::whereKey($this->vendor_id)->value('default_category_id') ?? '');
        }
    }

    public function save(bool $another = false): void
    {
        $this->amount = is_string($this->amount) ? trim($this->amount) : $this->amount;

        $data = $this->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'category_id' => ['required', $this->category_id === '__new' ? 'string' : Rule::exists('expense_categories', 'id')],
            'new_category' => [Rule::requiredIf($this->category_id === '__new'), 'nullable', 'string', 'max:60',
                new \App\Rules\UniqueCi('expense_categories')],
            'vendor_id' => ['nullable', $this->vendor_id === '__new' ? 'string' : Rule::exists('vendors', 'id')],
            'new_vendor' => [Rule::requiredIf($this->vendor_id === '__new'), 'nullable', 'string', 'max:80', new \App\Rules\UniqueCi('vendors')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'status' => ['required', Rule::in(['paid', 'unpaid', 'partial'])],
            'payment_mode' => [Rule::requiredIf($this->status !== 'unpaid'), 'nullable', Rule::in(array_keys(Expense::MODES))],
            'note' => ['nullable', 'string', 'max:255'],
            'bill' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'new_category.required' => 'Enter the new category name.',
            'new_vendor.required' => 'Enter the new vendor name.',
            'amount.gt' => 'Amount must be more than zero.',
        ], ['category_id' => 'category', 'vendor_id' => 'vendor']);

        if ($this->category_id === '__new') {
            $this->category_id = (string) ExpenseCategory::create([
                'name' => trim($this->new_category), 'color' => ExpenseCategory::nextColor(), 'sort' => 100,
            ])->id;
        }
        if ($this->vendor_id === '__new') {
            $this->vendor_id = (string) Vendor::create([
                'name' => trim($this->new_vendor), 'default_category_id' => $this->category_id,
            ])->id;
        }

        $expense = $this->editingId ? Expense::findOrFail($this->editingId) : new Expense(['created_by' => Auth::id()]);
        $amount = round((float) $data['amount'], 2);

        $directPaid = match ($this->status) {
            'paid' => $amount,
            'unpaid' => 0,
            default => min((float) $expense->direct_paid, $amount), // partial: keep what was already paid on the bill
        };

        $expense->fill([
            'date' => $data['date'],
            'category_id' => (int) $this->category_id,
            'vendor_id' => $this->vendor_id ? (int) $this->vendor_id : null,
            'amount' => $amount,
            'direct_paid' => $directPaid,
            'payment_mode' => $this->status === 'unpaid' ? null : $this->payment_mode,
            'paid_on' => $this->status === 'unpaid' ? null : ($expense->paid_on ?? $data['date']),
            'note' => $data['note'] ?: null,
            'updated_by' => Auth::id(),
        ]);

        $disk = Storage::disk(Expense::BILL_DISK);
        if (($this->bill || $this->removeBill) && $expense->bill_path) {
            $disk->delete($expense->bill_path);
            $expense->bill_path = null;
        }
        if ($this->bill) {
            $expense->bill_path = $this->bill->store('bills/'.substr($data['date'], 0, 7), Expense::BILL_DISK);
        }

        $expense->save();

        $msg = $this->editingId ? 'Expense updated' : 'Expense added';
        $this->month = substr($data['date'], 0, 7);
        $keep = ['date' => $this->date, 'category_id' => $this->category_id, 'vendor_id' => $this->vendor_id, 'status' => $this->status, 'payment_mode' => $this->payment_mode];
        $this->resetForm();

        if ($another) {
            // Same date / category / status, ready for the next bill.
            $this->fill($keep);
            $this->showForm = true;
            $this->dispatch('focus-amount');
        } else {
            $this->showForm = false;
        }
        $this->dispatch('toast', message: $msg);
    }

    public function markPaid(int $id, string $mode = 'cash'): void
    {
        $e = Expense::findOrFail($id);
        $e->fill([
            // Pay off whatever is still open on this bill, on the bill itself.
            'direct_paid' => min((float) $e->amount, (float) $e->direct_paid + $e->balance()),
            'payment_mode' => array_key_exists($mode, Expense::MODES) ? $mode : 'cash',
            'paid_on' => today(),
            'updated_by' => Auth::id(),
        ])->save();
        $this->dispatch('toast', message: 'Marked as paid');
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('delete-records');
        $this->confirmingDelete = $id;
    }

    public function delete(): void
    {
        Gate::authorize('delete-records');
        Expense::find($this->confirmingDelete)?->delete();
        $this->confirmingDelete = null;
        $this->dispatch('toast', message: 'Expense deleted');
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

        $monthQuery = Expense::between($start, $end);
        $filtered = (clone $monthQuery)
            ->when($this->filterCategory, fn ($q) => $q->where('category_id', $this->filterCategory))
            ->when($this->filterVendor, fn ($q) => $this->filterVendor === 'none' ? $q->whereNull('vendor_id') : $q->where('vendor_id', $this->filterVendor))
            ->when($this->filterStatus, fn ($q) => $this->filterStatus === 'due' ? $q->whereIn('status', ['unpaid', 'partial']) : $q->where('status', $this->filterStatus))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($w) => $w->where('note', 'like', $term)
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $term))
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', $term)));
            });

        $entries = (clone $filtered)->with(['category', 'vendor'])->orderByDesc('date')->orderByDesc('id')->get();
        $monthTotals = Expense::totals($monthQuery);
        $filteredTotals = Expense::totals($filtered);
        $categories = Expense::byCategory($monthQuery);
        $filtersOn = $this->filterCategory || $this->filterVendor || $this->filterStatus || trim($this->search) !== '';

        return view('livewire.expenses', [
            'entries' => $entries,
            'monthTotals' => $monthTotals,
            'filteredTotals' => $filteredTotals,
            'byCategory' => $categories,
            'filtersOn' => $filtersOn,
            'allCategories' => ExpenseCategory::ordered()->get(),
            'vendors' => Vendor::orderBy('name')->get(['id', 'name', 'is_active']),
            'monthLabel' => $start->format('F Y'),
            'isCurrentMonth' => $start->isSameMonth(now()),
            'allDue' => (float) Expense::whereIn('status', ['unpaid', 'partial'])->selectRaw('COALESCE(SUM(amount - paid_amount),0) s')->value('s'),
        ]);
    }

    private function normaliseMonth(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month) || $this->month > now()->format('Y-m')) {
            $this->month = now()->format('Y-m');
        }
    }

    private function monthStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'date', 'category_id', 'new_category', 'vendor_id', 'new_vendor', 'amount', 'status',
            'payment_mode', 'note', 'bill', 'existingBill', 'removeBill', 'khataPaid');
        $this->resetValidation();
    }
}
