<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Case-insensitive uniqueness ("Oil" == "oil"). MySQL's default collation already compares this way,
 * SQLite does not — this rule makes both behave the same.
 */
class UniqueCi implements ValidationRule
{
    public function __construct(private string $table, private string $column = 'name', private ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $exists = DB::table($this->table)
            ->whereRaw('LOWER('.$this->column.') = ?', [mb_strtolower(trim((string) $value))])
            ->when($this->ignoreId, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($exists) {
            $fail('The :attribute has already been taken.');
        }
    }
}
