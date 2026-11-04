<!DOCTYPE html>
<html lang="en">
<head>
    @include('components.layouts.head')
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
</head>
<body class="min-h-screen">
<div class="mx-auto max-w-3xl px-4 py-6 sm:py-10">
    <div class="mb-4 flex justify-end"><x-theme-toggle /></div>
    {{ $slot }}
    <div class="mt-10 flex items-center justify-center gap-2 text-xs text-slate-400">
        <x-brand-mark size="size-6" text="text-xs" radius="rounded-md" class="shadow-none" />
        <span>Read-only staff statement · {{ \App\Models\Setting::get('business_name') }}</span>
    </div>
</div>
<x-toast />
@livewireScripts
</body>
</html>
