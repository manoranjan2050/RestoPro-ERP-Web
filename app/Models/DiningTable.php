<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DiningTable extends Model
{
    protected $fillable = ['name', 'area', 'seats', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** The running (open) dine-in bill on this table, if any. */
    public function openOrder(): HasOne
    {
        return $this->hasOne(Order::class, 'table_id')->where('status', 'open')->where('type', 'dine_in')->latestOfMany();
    }

    public function label(): string
    {
        return $this->area ? $this->name.' · '.$this->area : $this->name;
    }
}
