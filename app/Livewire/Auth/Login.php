<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Sign in')]
class Login extends Component
{
    public string $identifier = '';
    public string $password = '';
    public bool $remember = true;

    public function login()
    {
        $this->validate([
            'identifier' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:200'],
        ], [], ['identifier' => 'mobile / username / email']);

        $key = 'login:'.Str::lower(trim($this->identifier)).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages([
                'identifier' => "Too many attempts. Try again in {$minutes} minute(s).",
            ]);
        }

        $user = User::findByIdentifier($this->identifier);

        if (! $user || ! Hash::check($this->password, $user->password)) {
            RateLimiter::hit($key, 15 * 60);
            $this->reset('password');
            throw ValidationException::withMessages([
                'identifier' => 'These details do not match our records.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['identifier' => 'This account is disabled.']);
        }

        RateLimiter::clear($key);
        Auth::login($user, $this->remember);
        session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return $this->redirectIntended(route('dashboard'), navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
