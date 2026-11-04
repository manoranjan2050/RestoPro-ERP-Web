<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Menu section on the POS: Cakes, Pastries, Snacks, Beverages … */
class ItemCategory extends Model
{
    protected $fillable = ['name', 'color', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'category_id');
    }

    public static function nextColor(): string
    {
        return ExpenseCategory::PALETTE[static::count() % count(ExpenseCategory::PALETTE)];
    }
}
