<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('My Profile')]
class Profile extends Component
{
    use WithFileUploads;

    public string $name = '';
    public string $username = '';
    public ?string $email = '';
    public ?string $mobile = '';
    public $photo;

    public string $current_password = '';
    public string $new_password = '';
    public string $new_password_confirmation = '';

    public function mount(): void
    {
        $u = Auth::user();
        $this->fill($u->only(['name', 'username', 'email', 'mobile']));
    }

    public function saveProfile(): void
    {
        $user = Auth::user();
        $this->username = mb_strtolower(trim($this->username));
        $this->email = $this->email ? mb_strtolower(trim($this->email)) : null;
        $this->mobile = User::normaliseMobile($this->mobile);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'username' => ['required', 'alpha_dash', 'min:3', 'max:40', Rule::unique('users')->ignore($user->id)],
            'email' => ['nullable', 'email', 'max:120', Rule::unique('users')->ignore($user->id)],
            'mobile' => ['nullable', 'digits:10', Rule::unique('users')->ignore($user->id)],
            'photo' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($this->photo) {
            $path = $this->photo->store('avatars', 'public');
            if ($user->photo_path) {
                Storage::disk('public')->delete($user->photo_path);
            }
            $user->photo_path = $path;
        }

        unset($data['photo']);
        $user->fill($data)->save();
        $this->reset('photo');

        $this->dispatch('toast', message: 'Profile updated');
    }

    public function removePhoto(): void
    {
        $user = Auth::user();
        if ($user->photo_path) {
            Storage::disk('public')->delete($user->photo_path);
            $user->forceFill(['photo_path' => null])->save();
        }
        $this->dispatch('toast', message: 'Photo removed');
    }

    public function changePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'new_password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = Auth::user();
        $user->forceFill([
            'password' => Hash::make($this->new_password),
            'remember_token' => \Illuminate\Support\Str::random(60),
        ])->save();
        // Sign out every other session (database session driver).
        \Illuminate\Support\Facades\DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', session()->getId())
            ->delete();
        $user->tokens()->delete(); // signs out the Android app too

        $this->reset('current_password', 'new_password', 'new_password_confirmation');
        $this->dispatch('toast', message: 'Password changed. Other devices were signed out.');
    }

    public function render()
    {
        return view('livewire.profile');
    }
}
