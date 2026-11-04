<?php

namespace App\Models;

use App\Models\Concerns\StoresPlainDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPayment extends Model
{
    use StoresPlainDate;

    /** type => [label, effect]. paid: money given to staff. bonus: extra earning that is also paid out. deduction: lowers earnings, no money moves. */
    public const TYPES = [
        'salary' => ['Salary', 'paid'],
        'advance' => ['Advance', 'paid'],
        'bonus' => ['Bonus', 'paid'],
        'deduction' => ['Deduction', 'deduct'],
    ];

    protected $fillable = ['staff_id', 'date', 'type', 'amount', 'payment_mode', 'for_month', 'note', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
