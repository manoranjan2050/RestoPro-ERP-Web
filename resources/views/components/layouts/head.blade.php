<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#f97316">
<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800&display=swap" rel="stylesheet" />
<script>
    // Apply saved theme before paint to avoid a light flash in dark mode.
    (function () {
        let t = 'system';
        try { t = localStorage.getItem('theme') || 'system'; } catch (e) {}
        const dark = t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
    })();
</script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
