<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    protected $fillable = ['name', 'color', 'sort', 'is_active'];

    public const PALETTE = ['#f59e0b', '#ef4444', '#10b981', '#0ea5e9', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1', '#84cc16', '#64748b'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort')->orderBy('name');
    }

    public static function nextColor(): string
    {
        return self::PALETTE[static::count() % count(self::PALETTE)];
    }
}
