<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    /** GET /attendance?date=Y-m-d — the daily register (staff working that day + their status). */
    public function day(Request $request)
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $date = $request->date ?? today()->toDateString();
        $marks = Attendance::whereDate('date', $date)->pluck('status', 'staff_id');
        $staff = $this->staffFor($date);

        return response()->json([
            'date' => $date,
            'unmarked' => $staff->whereNotIn('id', $marks->keys())->count(),
            'data' => $staff->map(fn ($s) => [
                'staff_id' => $s->id, 'name' => $s->name, 'designation' => $s->designation, 'photo_url' => $s->photoUrl(),
                'status' => $marks[$s->id] ?? null,
            ])->values(),
        ]);
    }

    /** PUT /attendance {staff_id, date, status: P|H|A|L|O|null} — null clears the mark. */
    public function set(Request $request)
    {
        $d = $request->validate([
            'staff_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'status' => ['nullable', Rule::in(array_keys(Attendance::STATUSES))],
        ]);
        $staff = Staff::employedOn($d['date'])->find($d['staff_id']);
        if (! $staff) {
            throw ValidationException::withMessages(['staff_id' => 'This staff member was not working on that date.']);
        }
        if (empty($d['status'])) {
            Attendance::where('staff_id', $staff->id)->whereDate('date', $d['date'])->delete();
        } else {
            Attendance::updateOrCreate(['staff_id' => $staff->id, 'date' => $d['date']], ['status' => $d['status']]);
        }

        return response()->json(['staff_id' => $staff->id, 'date' => $d['date'], 'status' => $d['status'] ?? null]);
    }

    /** POST /attendance/mark-all {date} — everyone not yet marked becomes Present. */
    public function markAll(Request $request)
    {
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $marked = Attendance::whereDate('date', $d['date'])->pluck('staff_id');
        $n = 0;
        foreach ($this->staffFor($d['date'])->whereNotIn('id', $marked) as $s) {
            Attendance::create(['staff_id' => $s->id, 'date' => $d['date'], 'status' => 'P']);
            $n++;
        }

        return response()->json(['marked' => $n]);
    }

    private function staffFor(string $date)
    {
        return Staff::employedOn($date)->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('leaving_date'))
            ->orderBy('name')->get();
    }
}
