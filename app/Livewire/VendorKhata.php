<?php

namespace App\Livewire;

use App\Models\Expense;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\VendorLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

class VendorKhata extends Component
{
    public Vendor $vendor;

    #[Url]
    public string $from = '';
    #[Url]
    public string $to = '';

    // Payment form
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $date = '';
    public $amount = '';
    public string $payment_mode = 'cash';
    public string $note = '';

    public ?int $confirmingDelete = null;

    public function mount(Vendor $vendor): void
    {
        $this->vendor = $vendor;
        if (request()->boolean('pay')) {
            $this->pay();
        }
    }

    public function pay(): void
    {
        $this->resetForm();
        $this->date = today()->toDateString();
        $due = $this->vendor->due();
        $this->amount = $due > 0 ? $this->plain($due) : '';
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = $this->vendor->payments()->findOrFail($id);
        $this->resetForm();
        $this->editingId = $p->id;
        $this->date = $p->date->toDateString();
        $this->amount = $this->plain($p->amount);
        $this->payment_mode = $p->payment_mode;
        $this->note = (string) $p->note;
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'payment_mode' => ['required', Rule::in(array_keys(Expense::MODES))],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['amount.gt' => 'Amount must be more than zero.']);

        $payload = [
            'date' => $data['date'],
            'amount' => round((float) $data['amount'], 2),
            'payment_mode' => $data['payment_mode'],
            'note' => $data['note'] ?: null,
            'updated_by' => Auth::id(),
        ];

        if ($this->editingId) {
            $this->vendor->payments()->findOrFail($this->editingId)->update($payload);
        } else {
            $this->vendor->payments()->create($payload + ['created_by' => Auth::id()]);
        }

        $this->dispatch('toast', message: $this->editingId ? 'Payment updated' : 'Payment recorded · '.money($payload['amount']));
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
        $this->vendor->payments()->find($this->confirmingDelete)?->delete();
        $this->confirmingDelete = null;
        $this->dispatch('toast', message: 'Payment deleted');
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->confirmingDelete = null;
        $this->resetForm();
    }

    public function clearRange(): void
    {
        $this->reset('from', 'to');
    }

    public function render()
    {
        $this->vendor->refresh();
        $from = $this->validDate($this->from);
        $to = $this->validDate($this->to);
        $statement = VendorLedger::statement($this->vendor, $from, $to);
        $balance = $this->vendor->due();

        $open = Expense::where('vendor_id', $this->vendor->id)->whereIn('status', ['unpaid', 'partial'])
            ->with('category')->orderBy('date')->get();

        $totals = [
            'billed' => (float) $this->vendor->expenses()->sum('amount'),
            'paidAtPurchase' => (float) $this->vendor->expenses()->sum('direct_paid'),
            'payments' => (float) $this->vendor->payments()->sum('amount'),
        ];

        $cur = \App\Models\Setting::get('currency_symbol', '₹');
        $business = \App\Models\Setting::get('business_name');
        $waText = "Namaste {$this->vendor->name},\n".
            ($balance > 0
                ? "As per our records, the pending amount is {$cur}".inr_group($balance, fmod($balance, 1) > 0 ? 2 : 0).' as of '.today()->format('d M Y').'.'
                : 'Our account with you is fully settled as of '.today()->format('d M Y').'.').
            "\n— {$business}";

        return view('livewire.vendor-khata', [
            'statement' => $statement,
            'balance' => $balance,
            'open' => $open,
            'totals' => $totals,
            'filtered' => $from || $to,
            'waLink' => $this->vendor->mobile ? 'https://wa.me/91'.$this->vendor->mobile.'?text='.rawurlencode($waText) : null,
        ])->title($this->vendor->name.' · Khata');
    }

    private function validDate(string $v): ?Carbon
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? Carbon::parse($v)->startOfDay() : null;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'date', 'amount', 'payment_mode', 'note');
        $this->resetValidation();
    }

    private function plain($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }
}
