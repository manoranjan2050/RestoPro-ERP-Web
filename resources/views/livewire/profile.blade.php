@php $user = auth()->user(); @endphp
<div class="grid gap-6 lg:grid-cols-3">
    {{-- Summary card --}}
    <div class="card overflow-hidden lg:col-span-1">
        <div class="h-24 bg-gradient-to-r from-brand-500 via-rose-500 to-violet-600"></div>
        <div class="-mt-12 flex flex-col items-center px-6 pb-6 text-center">
            @if ($photo && method_exists($photo, 'isPreviewable') && $photo->isPreviewable())
                <img src="{{ $photo->temporaryUrl() }}" class="size-24 rounded-full object-cover ring-4 ring-white dark:ring-slate-900" alt="New photo preview">
            @else
                <x-avatar :user="$user" class="size-24 ring-4" text="text-3xl" />
            @endif
            <h2 class="mt-3 text-lg font-bold">{{ $user->name }}</h2>
            <p class="text-sm text-slate-500">{{ '@'.$user->username }}</p>
            <span class="mt-2 rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold capitalize text-brand-700 dark:bg-brand-500/10 dark:text-brand-400">{{ $user->role }}</span>
            <dl class="mt-5 w-full space-y-2 text-left text-sm">
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Mobile</dt><dd class="font-medium">{{ $user->mobile ?: '—' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Email</dt><dd class="truncate font-medium">{{ $user->email ?: '—' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">Last sign-in</dt><dd class="font-medium">{{ $user->last_login_at?->format('d M Y, h:i A') ?? '—' }}</dd></div>
            </dl>
        </div>
    </div>

    <div class="space-y-6 lg:col-span-2">
        {{-- Profile form --}}
        <form wire:submit="saveProfile" class="card p-5 sm:p-6">
            <h3 class="text-base font-bold">Profile details</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">You can sign in with your mobile number, username or email.</p>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <label class="btn-ghost cursor-pointer">
                    <x-icon name="camera" class="size-4" /> Change photo
                    <input type="file" wire:model="photo" accept="image/*" class="sr-only">
                </label>
                @if ($user->photo_path)
                    <button type="button" wire:click="removePhoto" class="btn text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">Remove</button>
                @endif
                <span wire:loading wire:target="photo" class="text-sm text-slate-500">Uploading…</span>
            </div>
            @error('photo') <p class="error">{{ $message }}</p> @enderror

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="label" for="name">Full name</label>
                    <input id="name" wire:model="name" class="input">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="username">Username</label>
                    <input id="username" wire:model="username" class="input" autocomplete="username">
                    @error('username') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="mobile">Mobile number</label>
                    <input id="mobile" wire:model="mobile" class="input" inputmode="numeric" placeholder="10-digit mobile">
                    @error('mobile') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="label" for="email">Email</label>
                    <input id="email" wire:model="email" type="email" class="input" placeholder="optional">
                    @error('email') <p class="error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveProfile,photo">Save profile</button>
            </div>
        </form>

        {{-- Password form --}}
        <form wire:submit="changePassword" class="card p-5 sm:p-6">
            <h3 class="text-base font-bold">Change password</h3>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Changing it signs you out on every other device.</p>
            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="label" for="current_password">Current password</label>
                    <input id="current_password" wire:model="current_password" type="password" class="input" autocomplete="current-password">
                    @error('current_password') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="new_password">New password</label>
                    <input id="new_password" wire:model="new_password" type="password" class="input" autocomplete="new-password">
                    @error('new_password') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="new_password_confirmation">Confirm new password</label>
                    <input id="new_password_confirmation" wire:model="new_password_confirmation" type="password" class="input" autocomplete="new-password">
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="changePassword">Update password</button>
            </div>
        </form>
    </div>
</div>
