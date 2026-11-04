<?php

namespace App\Livewire;

use App\Livewire\Concerns\EditsStaff;
use App\Models\Attendance;
use App\Models\Staff;
use App\Services\StaffLedger;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Staff')]
class StaffList extends Component
{
    use EditsStaff, WithFileUploads;

    #[Url(as: 'q')]
    public string $search = '';
    #[Url(as: 'show')]
    public string $show = 'active'; // active | left | all

    public function mount(): void
    {
        $this->sf = $this->blankStaff();
    }

    protected function afterStaffSaved(Staff $staff): void
    {
        if (! $this->staffId) {
            $this->redirectRoute('staff.show', $staff, navigate: true);
        }
    }

    public function render()
    {
        $staff = Staff::query()
            ->when($this->show === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->show === 'left', fn ($q) => $q->where('is_active', false))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.trim($this->search).'%')
                ->orWhere('mobile', 'like', '%'.trim($this->search).'%')
                ->orWhere('designation', 'like', '%'.trim($this->search).'%')))
            ->orderBy('name')
            ->get();

        $today = Attendance::whereDate('date', today()->toDateString())->pluck('status', 'staff_id');
        $ym = now()->format('Y-m');
        $cards = $staff->map(function (Staff $s) use ($today, $ym) {
            $months = StaffLedger::months($s);
            $current = $months->firstWhere('month', $ym);

            return [
                'staff' => $s,
                'balance' => (float) ($months->last()['closing'] ?? $s->opening_balance),
                'month_earned' => (float) ($current['earned'] ?? 0),
                'today' => $today[$s->id] ?? null,
            ];
        });

        $active = Staff::active()->get();
        $activeBalances = $active->map(fn ($s) => StaffLedger::balance($s));

        return view('livewire.staff-list', [
            'cards' => $cards,
            'activeCount' => $active->count(),
            'monthlyBill' => $active->sum(fn ($s) => $s->salary_type === 'monthly' ? (float) $s->salary_amount : 0),
            'owed' => $activeBalances->filter(fn ($b) => $b > 0)->sum(),
            'advances' => abs($activeBalances->filter(fn ($b) => $b < 0)->sum()),
            'markedToday' => $today->count(),
        ]);
    }
}
