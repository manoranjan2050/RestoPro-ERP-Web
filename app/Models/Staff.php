<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Staff extends Model
{
    use \App\Models\Concerns\StoresPlainDate;

    protected $table = 'staff';

    public const TYPES = ['monthly' => 'Monthly salary', 'daily' => 'Daily wage'];

    protected $fillable = [
        'name', 'mobile', 'photo_path', 'designation', 'joining_date', 'leaving_date', 'salary_type', 'salary_amount',
        'opening_balance', 'id_type', 'id_number', 'address', 'emergency_contact', 'note', 'is_active',
    ];

    protected $hidden = ['share_pin'];

    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
            'leaving_date' => 'date',
            'salary_amount' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected function plainDateColumns(): array
    {
        return ['joining_date', 'leaving_date'];
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(StaffPayment::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Staff employed on a given day (joined on/before, not left before). */
    public function scopeEmployedOn(Builder $q, Carbon|string $date): Builder
    {
        $d = Carbon::parse($date)->toDateString();

        return $q->where('joining_date', '<=', $d)
            ->where(fn ($w) => $w->whereNull('leaving_date')->orWhere('leaving_date', '>=', $d));
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('media', $this->photo_path) : null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return mb_strtoupper(mb_substr($parts[0] ?? 'S', 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    }

    public function rateLabel(): string
    {
        return money($this->salary_amount).($this->salary_type === 'daily' ? ' / day' : ' / month');
    }
}
