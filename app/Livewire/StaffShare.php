<?php

namespace App\Livewire;

use App\Models\Attendance;
use App\Models\Setting;
use App\Models\Staff;
use App\Services\StaffLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Public, read-only page for one staff member: /s/{token}. No login.
 * Shows only that person's attendance, payments and balance — never anything else in the restaurant.
 */
#[Layout('components.layouts.public')]
class StaffShare extends Component
{
    #[Locked]
    public string $token;

    public string $month = '';
    public ?int $fy = null;
    public string $pin = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $staff = $this->staff();
        $this->month = now()->format('Y-m') < $staff->joining_date->format('Y-m') ? $staff->joining_date->format('Y-m') : now()->format('Y-m');
    }

    public function unlock(): void
    {
        $staff = $this->staff();
        $key = 'share-pin:'.$staff->id.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('pin', 'Too many tries. Wait '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).');

            return;
        }
        if (! preg_match('/^\d{4}$/', $this->pin) || ! Hash::check($this->pin, (string) $staff->share_pin)) {
            RateLimiter::hit($key, 10 * 60);
            $this->pin = '';
            $this->addError('pin', 'Wrong PIN.');

            return;
        }
        RateLimiter::clear($key);
        session()->put($this->sessionKey($staff), true);
        $this->pin = '';
    }

    public function shiftMonth(int $delta): void
    {
        $staff = $this->staff();
        $m = Carbon::parse($this->month.'-01')->addMonthsNoOverflow($delta)->format('Y-m');
        if ($m >= $staff->joining_date->format('Y-m') && $m <= now()->format('Y-m')) {
            $this->month = $m;
        }
    }

    public function render()
    {
        $staff = $this->staff();
        $locked = $staff->share_pin && ! session()->get($this->sessionKey($staff));
        $business = Setting::get('business_name');

        if ($locked) {
            return view('livewire.staff-share', compact('staff', 'locked', 'business'))->title($staff->name);
        }

        if (! preg_match('/^\d{4}-\d{2}$/', $this->month) || $this->month > now()->format('Y-m') || $this->month < $staff->joining_date->format('Y-m')) {
            $this->month = now()->format('Y-m');
        }

        $months = StaffLedger::months($staff);
        $current = $months->firstWhere('month', $this->month) ?? StaffLedger::month($staff, $this->month);
        $start = Carbon::parse($this->month.'-01');
        $marks = Attendance::where('staff_id', $staff->id)
            ->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->get()->mapWithKeys(fn ($a) => [$a->date->format('j') => $a->status]);

        $startMonth = (int) Setting::get('fy_start_month', '4');
        $years = StaffLedger::financialYears($staff, $startMonth);
        if (! $this->fy || ! isset($years[$this->fy])) {
            $this->fy = array_key_first($years);
        }
        $yearRows = StaffLedger::yearRows($staff, $this->fy, $startMonth);

        return view('livewire.staff-share', [
            'staff' => $staff,
            'locked' => false,
            'business' => $business,
            'balance' => (float) ($months->last()['closing'] ?? $staff->opening_balance),
            'current' => $current,
            'marks' => $marks,
            'start' => $start,
            'payments' => $staff->payments()->orderByDesc('date')->orderByDesc('id')->limit(30)->get(),
            'years' => $years,
            'yearRows' => $yearRows,
            'yearTotals' => StaffLedger::totals($yearRows),
        ])->title($staff->name);
    }

    private function staff(): Staff
    {
        $staff = strlen($this->token) >= 20 ? Staff::where('share_token', $this->token)->first() : null;
        abort_unless($staff, 404);

        return $staff;
    }

    private function sessionKey(Staff $staff): string
    {
        // Tied to the token, so a regenerated link or changed PIN needs unlocking again.
        return 'share-ok.'.$staff->id.'.'.md5($this->token.'|'.$staff->share_pin);
    }
}
