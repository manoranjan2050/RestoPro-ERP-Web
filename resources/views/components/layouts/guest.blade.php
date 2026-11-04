<!DOCTYPE html>
<html lang="en">
<head>
    @include('components.layouts.head')
</head>
<body class="min-h-screen">
<div class="relative flex min-h-screen items-center justify-center overflow-clip p-4">
    {{-- decorative gradient blobs --}}
    <div class="pointer-events-none absolute -left-32 -top-32 size-96 rounded-full bg-brand-400/30 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-32 -right-32 size-96 rounded-full bg-rose-500/25 blur-3xl"></div>
    <div class="pointer-events-none absolute right-1/3 top-1/4 size-64 rounded-full bg-violet-500/15 blur-3xl"></div>

    <div class="absolute right-4 top-4"><x-theme-toggle /></div>

    <div class="relative w-full max-w-md">
        {{ $slot }}
        <x-dev-credit class="mt-6" />
    </div>
</div>
@livewireScripts
</body>
</html>
