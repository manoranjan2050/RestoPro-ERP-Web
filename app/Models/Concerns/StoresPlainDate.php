<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;

/**
 * Store calendar-date columns as a bare Y-m-d. Eloquent's `date` cast would otherwise write "Y-m-d 00:00:00",
 * which breaks equality/range comparisons on SQLite (MySQL DATE columns hide the problem).
 * Models list their columns in plainDateColumns(); default is just `date`.
 */
trait StoresPlainDate
{
    protected function plainDateColumns(): array
    {
        return ['date'];
    }

    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->plainDateColumns(), true)) {
            $this->attributes[$key] = ($value === null || $value === '') ? null : Carbon::parse($value)->toDateString();

            return $this;
        }

        return parent::setAttribute($key, $value);
    }
}
