<div class="card p-6 shadow-xl shadow-slate-900/5 sm:p-8">
    <div class="mb-8 text-center">
        <x-brand-mark size="size-14" text="text-2xl" radius="rounded-2xl" class="mx-auto mb-4" />
        <h1 class="text-2xl font-extrabold tracking-tight">Welcome back</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Sign in to {{ \App\Models\Setting::get('business_name') }}</p>
    </div>

    @if (session('status'))
        <p class="mb-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    @if (config('restopro.demo.username') && config('restopro.demo.password'))
        <div class="mb-6 rounded-2xl border border-brand-200 bg-brand-50 p-4 text-sm dark:border-brand-500/30 dark:bg-brand-500/10">
            <p class="font-semibold text-brand-700 dark:text-brand-300">Demo — try it with sample data</p>
            <p class="mt-1 text-slate-600 dark:text-slate-300">Username <b>{{ config('restopro.demo.username') }}</b> · Password <b>{{ config('restopro.demo.password') }}</b></p>
            <button type="button" wire:click="useDemo" class="btn-primary mt-3 w-full py-2.5">Use the demo login</button>
        </div>
    @endif

    <form wire:submit="login" class="space-y-5" novalidate>
        <div>
            <label for="identifier" class="label">Mobile, username or email</label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><x-icon name="user" class="size-4" /></span>
                <input wire:model="identifier" id="identifier" type="text" autocomplete="username" autofocus
                       class="input py-2.5 pl-9" placeholder="98765 43210 · admin · you@mail.com">
            </div>
            @error('identifier') <p class="error">{{ $message }}</p> @enderror
        </div>

        <div x-data="{ show: false }">
            <label for="password" class="label">Password</label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400"><x-icon name="lock" class="size-4" /></span>
                <input wire:model="password" id="password" :type="show ? 'text' : 'password'" autocomplete="current-password"
                       class="input py-2.5 pl-9 pr-10" placeholder="••••••••">
                <button type="button" @click="show = !show" class="absolute inset-y-0 right-2 flex items-center px-1 text-slate-400 hover:text-slate-600" :aria-label="show ? 'Hide password' : 'Show password'">
                    <x-icon name="eye" class="size-4" />
                </button>
            </div>
            @error('password') <p class="error">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
            <input wire:model="remember" type="checkbox" class="rounded border-slate-300 text-brand-500 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
            Keep me signed in
        </label>

        <button type="submit" class="btn-primary w-full py-3" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>
</div>
