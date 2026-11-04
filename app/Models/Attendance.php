<?php

namespace App\Models;

use App\Models\Concerns\StoresPlainDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use StoresPlainDate;

    /** code => [label, short, tailwind classes] */
    public const STATUSES = [
        'P' => ['Present', 'P', 'bg-emerald-500 text-white'],
        'H' => ['Half day', 'H', 'bg-amber-400 text-white'],
        'A' => ['Absent', 'A', 'bg-rose-500 text-white'],
        'L' => ['Paid leave', 'L', 'bg-sky-500 text-white'],
        'O' => ['Week off', 'O', 'bg-slate-400 text-white'],
    ];

    protected $fillable = ['staff_id', 'date', 'status', 'note'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** Tap order for calendars/grids: blank → P → H → A → L → O → blank. Returns the new status (null = cleared). */
    public static function cycle(Staff $staff, string $date): ?string
    {
        $date = \Illuminate\Support\Carbon::parse($date)->toDateString();
        if ($date > today()->toDateString() || $date < $staff->joining_date->toDateString()
            || ($staff->leaving_date && $date > $staff->leaving_date->toDateString())) {
            return null;
        }
        $order = ['', 'P', 'H', 'A', 'L', 'O'];
        $current = static::where('staff_id', $staff->id)->whereDate('date', $date)->value('status') ?? '';
        $next = $order[(array_search($current, $order, true) + 1) % count($order)];
        if ($next === '') {
            static::where('staff_id', $staff->id)->whereDate('date', $date)->delete();

            return null;
        }
        static::updateOrCreate(['staff_id' => $staff->id, 'date' => $date], ['status' => $next]);

        return $next;
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
