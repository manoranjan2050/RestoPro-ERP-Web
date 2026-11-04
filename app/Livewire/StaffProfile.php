<?php

namespace App\Livewire;

use App\Livewire\Concerns\EditsStaff;
use App\Models\Attendance;
use App\Models\Expense;
use App\Models\Staff;
use App\Models\StaffPayment;
use App\Services\StaffLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

class StaffProfile extends Component
{
    use EditsStaff, WithFileUploads;

    public Staff $staff;

    #[Url]
    public string $tab = 'overview'; // overview | attendance | payments
    #[Url(as: 'm')]
    public string $month = '';

    // Payment form
    public bool $showPayForm = false;
    public ?int $payId = null;
    public string $p_date = '';
    public string $p_type = 'salary';
    public $p_amount = '';
    public string $p_mode = 'cash';
    public string $p_for_month = '';
    public string $p_note = '';

    public ?int $confirmingDelete = null;

    #[Url(as: 'fy')]
    public ?int $fy = null;

    // Share link
    public bool $showShare = false;
    public string $sharePin = '';

    // ---- Share link --------------------------------------------------------

    public function openShare(): void
    {
        $this->sharePin = '';
        $this->resetValidation();
        $this->showShare = true;
    }

    public function closeShare(): void
    {
        $this->showShare = false;
        $this->sharePin = '';
        $this->resetValidation();
    }

    public function createShareLink(): void
    {
        $this->staff->forceFill(['share_token' => self::newToken()])->save();
        $this->dispatch('toast', message: 'Share link created');
    }

    /** New token: the old link stops working immediately. */
    public function regenerateShareLink(): void
    {
        $this->staff->forceFill(['share_token' => self::newToken()])->save();
        $this->dispatch('toast', message: 'New link created · old link no longer works');
    }

    public function revokeShareLink(): void
    {
        $this->staff->forceFill(['share_token' => null, 'share_pin' => null])->save();
        $this->dispatch('toast', message: 'Share link turned off');
    }

    public function savePin(): void
    {
        $this->validate(['sharePin' => ['required', 'digits:4']], ['sharePin.digits' => 'PIN must be exactly 4 digits.'], ['sharePin' => 'PIN']);
        $this->staff->forceFill(['share_pin' => \Illuminate\Support\Facades\Hash::make($this->sharePin)])->save();
        $this->sharePin = '';
        $this->dispatch('toast', message: 'PIN set · tell the staff member their PIN');
    }

    public function removePin(): void
    {
        $this->staff->forceFill(['share_pin' => null])->save();
        $this->dispatch('toast', message: 'PIN removed');
    }

    private static function newToken(): string
    {
        return \Illuminate\Support\Str::random(32);
    }

    public function mount(Staff $staff): void
    {
        $this->staff = $staff;
        $this->sf = $this->blankStaff();
        $this->normaliseMonth();
    }

    public function updatedMonth(): void
    {
        $this->normaliseMonth();
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = Carbon::parse($this->month.'-01')->addMonthsNoOverflow($delta)->format('Y-m');
        $this->normaliseMonth();
    }

    public function toggleDay(string $date): void
    {
        Attendance::cycle($this->staff, $date);
    }

    protected function afterStaffSaved(Staff $staff): void
    {
        $this->staff = $staff->fresh();
    }

    // ---- Payments ----------------------------------------------------------

    public function newPayment(string $type = 'salary'): void
    {
        $this->resetPayForm();
        $this->p_type = $type;
        $this->p_date = today()->toDateString();
        // Early in the month, salary is usually for the previous month.
        $this->p_for_month = (today()->day <= 10 ? today()->subMonthNoOverflow() : today())->format('Y-m');
        if ($type === 'salary') {
            $bal = StaffLedger::balance($this->staff);
            $this->p_amount = $bal > 0 ? $this->plainAmount($bal) : '';
        }
        $this->showPayForm = true;
    }

    public function editPayment(int $id): void
    {
        $p = $this->staff->payments()->findOrFail($id);
        $this->resetPayForm();
        $this->payId = $p->id;
        $this->p_date = $p->date->toDateString();
        $this->p_type = $p->type;
        $this->p_amount = $this->plainAmount($p->amount);
        $this->p_mode = $p->payment_mode ?? 'cash';
        $this->p_for_month = (string) $p->for_month;
        $this->p_note = (string) $p->note;
        $this->showPayForm = true;
    }

    public function savePayment(): void
    {
        $this->validate([
            'p_date' => ['required', 'date', 'before_or_equal:today'],
            'p_type' => ['required', Rule::in(array_keys(StaffPayment::TYPES))],
            'p_amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'p_mode' => [Rule::requiredIf($this->p_type !== 'deduction'), 'nullable', Rule::in(array_keys(Expense::MODES))],
            'p_for_month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'p_note' => ['nullable', 'string', 'max:255'],
        ], ['p_amount.gt' => 'Amount must be more than zero.'], [
            'p_date' => 'date', 'p_amount' => 'amount', 'p_mode' => 'payment mode', 'p_note' => 'note', 'p_for_month' => 'month',
        ]);

        $data = [
            'date' => $this->p_date,
            'type' => $this->p_type,
            'amount' => round((float) $this->p_amount, 2),
            'payment_mode' => $this->p_type === 'deduction' ? null : $this->p_mode,
            'for_month' => $this->p_type === 'salary' ? ($this->p_for_month ?: substr($this->p_date, 0, 7)) : null,
            'note' => trim($this->p_note) ?: null,
            'updated_by' => Auth::id(),
        ];

        if ($this->payId) {
            $this->staff->payments()->findOrFail($this->payId)->update($data);
        } else {
            $this->staff->payments()->create($data + ['created_by' => Auth::id()]);
        }

        $label = StaffPayment::TYPES[$this->p_type][0];
        $this->dispatch('toast', message: $this->payId ? "{$label} updated" : "{$label} of ".money($data['amount']).' saved');
        $this->showPayForm = false;
        $this->resetPayForm();
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('delete-records');
        $this->confirmingDelete = $id;
    }

    public function deletePayment(): void
    {
        Gate::authorize('delete-records');
        $this->staff->payments()->whereKey($this->confirmingDelete)->delete();
        $this->confirmingDelete = null;
        $this->dispatch('toast', message: 'Entry deleted');
    }

    public function cancelPay(): void
    {
        $this->showPayForm = false;
        $this->confirmingDelete = null;
        $this->resetPayForm();
    }

    public function render()
    {
        $this->staff->refresh();
        $months = StaffLedger::months($this->staff);
        $balance = (float) ($months->last()['closing'] ?? $this->staff->opening_balance);
        $current = StaffLedger::month($this->staff, $this->month);

        $start = Carbon::parse($this->month.'-01');
        $marks = Attendance::where('staff_id', $this->staff->id)
            ->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->get()->mapWithKeys(fn ($a) => [$a->date->format('j') => $a->status]);

        $payments = $this->staff->payments()->orderByDesc('date')->orderByDesc('id')
            ->when($this->tab !== 'payments', fn ($q) => $q->limit(5))->get();

        return view('livewire.staff-profile', [
            'balance' => $balance,
            'current' => $current,
            'marks' => $marks,
            'start' => $start,
            'payments' => $payments,
            'isCurrentMonth' => $start->isSameMonth(now()),
            'minMonth' => $this->staff->joining_date->format('Y-m'),
            ...$this->ledgerData(),
            'shareUrl' => $this->staff->share_token ? route('staff.share', $this->staff->share_token) : null,
            'hasPin' => (bool) $this->staff->share_pin,
        ])->title($this->staff->name);
    }

    private function ledgerData(): array
    {
        if ($this->tab !== 'ledger') {
            return ['years' => [], 'yearRows' => collect(), 'yearTotals' => [], 'fyLabel' => ''];
        }
        $startMonth = (int) \App\Models\Setting::get('fy_start_month', '4');
        $years = StaffLedger::financialYears($this->staff, $startMonth);
        if (! $this->fy || ! isset($years[$this->fy])) {
            $this->fy = array_key_first($years);
        }
        $rows = StaffLedger::yearRows($this->staff, $this->fy, $startMonth);

        return [
            'years' => $years,
            'yearRows' => $rows,
            'yearTotals' => StaffLedger::totals($rows),
            'fyLabel' => $years[$this->fy],
        ];
    }

    private function normaliseMonth(): void
    {
        $min = $this->staff->joining_date->format('Y-m');
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month) || $this->month > now()->format('Y-m')) {
            $this->month = now()->format('Y-m');
        }
        if ($this->month < $min) {
            $this->month = $min;
        }
    }

    private function resetPayForm(): void
    {
        $this->reset('payId', 'p_date', 'p_type', 'p_amount', 'p_mode', 'p_for_month', 'p_note');
        $this->resetValidation();
    }
}
