<?php

namespace App\Livewire;

use App\Models\Attendance;
use App\Models\Staff;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Attendance')]
class AttendanceRegister extends Component
{
    #[Url]
    public string $view = 'day'; // day | month
    #[Url(as: 'd')]
    public string $date = '';
    #[Url(as: 'm')]
    public string $month = '';

    public function mount(): void
    {
        $this->normalise();
    }

    public function updated(): void
    {
        $this->normalise();
    }

    public function shift(int $delta): void
    {
        if ($this->view === 'day') {
            $this->date = Carbon::parse($this->date)->addDays($delta)->toDateString();
        } else {
            $this->month = Carbon::parse($this->month.'-01')->addMonthsNoOverflow($delta)->format('Y-m');
        }
        $this->normalise();
    }

    /** Set a status; tapping the current status again clears it. */
    public function mark(int $staffId, string $status, ?string $date = null): void
    {
        $date = Carbon::parse($date ?? $this->date)->toDateString();
        abort_if($date > today()->toDateString(), 422, 'Future date');
        abort_unless(array_key_exists($status, Attendance::STATUSES), 422);
        $staff = Staff::employedOn($date)->findOrFail($staffId);

        $existing = Attendance::where('staff_id', $staff->id)->whereDate('date', $date)->first();
        if ($existing && $existing->status === $status) {
            $existing->delete();

            return;
        }
        Attendance::updateOrCreate(['staff_id' => $staff->id, 'date' => $date], ['status' => $status]);
    }

    public function cycle(int $staffId, string $date): void
    {
        Attendance::cycle(Staff::findOrFail($staffId), $date);
    }

    public function markAllPresent(): void
    {
        $marked = Attendance::whereDate('date', $this->date)->pluck('staff_id');
        $n = 0;
        foreach ($this->staffFor($this->date)->whereNotIn('id', $marked) as $s) {
            Attendance::create(['staff_id' => $s->id, 'date' => $this->date, 'status' => 'P']);
            $n++;
        }
        $this->dispatch('toast', message: $n ? "{$n} marked present" : 'Everyone is already marked');
    }

    public function render()
    {
        if ($this->view === 'month') {
            $start = Carbon::parse($this->month.'-01');
            $end = $start->copy()->endOfMonth()->startOfDay();
            $staff = Staff::where('joining_date', '<=', $end->toDateString())
                ->where(fn ($q) => $q->whereNull('leaving_date')->orWhere('leaving_date', '>=', $start->toDateString()))
                ->orderByDesc('is_active')->orderBy('name')->get();
            $grid = Attendance::whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->get(['staff_id', 'date', 'status'])
                ->groupBy('staff_id')
                ->map(fn ($rows) => $rows->mapWithKeys(fn ($a) => [(int) $a->date->format('j') => $a->status]));

            return view('livewire.attendance-register', [
                'staff' => $staff, 'grid' => $grid, 'start' => $start, 'days' => $start->daysInMonth,
                'label' => $start->format('F Y'), 'isCurrent' => $start->isSameMonth(now()),
            ]);
        }

        $staff = $this->staffFor($this->date);
        $marks = Attendance::whereDate('date', $this->date)->pluck('status', 'staff_id');
        $summary = collect(Attendance::STATUSES)->map(fn ($v, $k) => $marks->filter(fn ($s) => $s === $k)->count());

        return view('livewire.attendance-register', [
            'staff' => $staff, 'marks' => $marks, 'summary' => $summary,
            'unmarked' => $staff->count() - $marks->only($staff->pluck('id'))->count(),
            'label' => Carbon::parse($this->date)->format('l, d M Y'), 'isCurrent' => $this->date === today()->toDateString(),
        ]);
    }

    private function staffFor(string $date)
    {
        return Staff::employedOn($date)->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('leaving_date'))
            ->orderBy('name')->get();
    }

    private function normalise(): void
    {
        if (! in_array($this->view, ['day', 'month'], true)) {
            $this->view = 'day';
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) || ! strtotime($this->date) || $this->date > today()->toDateString()) {
            $this->date = today()->toDateString();
        }
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month) || $this->month > now()->format('Y-m')) {
            $this->month = now()->format('Y-m');
        }
    }
}
