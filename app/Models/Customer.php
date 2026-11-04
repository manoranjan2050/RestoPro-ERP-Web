<?php

namespace App\Models;

use App\Models\Concerns\StoresPlainDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use StoresPlainDate;

    protected $fillable = ['name', 'mobile', 'email', 'address', 'birthday', 'anniversary', 'gstin', 'note'];

    protected function casts(): array
    {
        return ['birthday' => 'date', 'anniversary' => 'date'];
    }

    protected function plainDateColumns(): array
    {
        return ['birthday', 'anniversary'];
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $digits = preg_replace('/\D/', '', $term);

        return $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
            ->when(strlen($digits) >= 3, fn ($x) => $x->orWhere('mobile', 'like', "%{$digits}%")));
    }

    public function initials(): string
    {
        $p = preg_split('/\s+/', trim($this->name)) ?: [];

        return mb_strtoupper(mb_substr($p[0] ?? 'C', 0, 1).(count($p) > 1 ? mb_substr(end($p), 0, 1) : ''));
    }

    public function whatsappUrl(string $text = ''): ?string
    {
        return $this->mobile ? 'https://wa.me/91'.$this->mobile.($text !== '' ? '?text='.rawurlencode($text) : '') : null;
    }
}
