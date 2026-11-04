<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_OWNER = 'owner';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_CASHIER = 'cashier'; // v2: POS + orders only

    public const ROLES = [
        self::ROLE_OWNER => 'Owner — everything',
        self::ROLE_MANAGER => 'Manager — enter & edit, no delete/backup/settings',
        self::ROLE_CASHIER => 'Cashier — sales counter only (Advanced mode)',
    ];

    protected $fillable = [
        'name', 'username', 'email', 'mobile', 'photo_path', 'role', 'is_active', 'password', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function isCashier(): bool
    {
        return $this->role === self::ROLE_CASHIER;
    }

    /** Where this user lands after sign-in. */
    public function homeRoute(): string
    {
        if ($this->isCashier()) {
            return \App\Support\Sales::advanced() && \Illuminate\Support\Facades\Route::has('pos') ? 'pos' : 'profile';
        }

        return 'dashboard';
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    /** Public URL for the profile photo, or null when none is set. */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('media', $this->photo_path) : null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $first = mb_substr($parts[0] ?? 'U', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * Normalise an Indian mobile number to its last 10 digits so
     * "+91 98765-43210", "09876543210" and "9876543210" all match.
     */
    public static function normaliseMobile(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $value);

        return $digits === '' ? null : substr($digits, -10);
    }

    /** Find an active user by email, mobile number or username. */
    public static function findByIdentifier(string $identifier): ?self
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return static::where('email', mb_strtolower($identifier))->first();
        }

        if (preg_match('/^[\d\s+\-()]{10,}$/', $identifier)) {
            return static::where('mobile', static::normaliseMobile($identifier))->first();
        }

        return static::where('username', mb_strtolower($identifier))->first();
    }
}
