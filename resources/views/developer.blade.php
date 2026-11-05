<!DOCTYPE html>
<html lang="en">
<head>
    @include('components.layouts.head', ['title' => 'MANORANJAN — developer of OpenRestoPro ERP'])
    <meta name="description" content="Manoranjan is a full-stack developer, IoT engineer and PCB designer building Android apps, web platforms and smart-home tools under MTP Code.">
    <style>
        @keyframes blob { 0%,100% { transform: translate(0,0) scale(1); } 33% { transform: translate(40px,-60px) scale(1.15); } 66% { transform: translate(-30px,30px) scale(.9); } }
        @keyframes spin-slow { to { transform: rotate(360deg); } }
        @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
        @keyframes rise { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: none; } }
        @keyframes shine { 0% { background-position: 0% 50%; } 100% { background-position: 200% 50%; } }
        @keyframes caret { 50% { opacity: 0; } }
        .blob { animation: blob 14s ease-in-out infinite; }
        .ring-spin { animation: spin-slow 6s linear infinite; }
        .float { animation: float 4s ease-in-out infinite; }
        .rise { opacity: 0; animation: rise .8s cubic-bezier(.2,.7,.2,1) forwards; }
        .shine { background-size: 200% auto; animation: shine 4s linear infinite; }
        .caret::after { content: '|'; margin-left: 2px; animation: caret 1s steps(1) infinite; }
        .tilt { transition: transform .25s ease, box-shadow .25s ease; transform-style: preserve-3d; }
        .grid-bg { background-image: linear-gradient(rgba(148,163,184,.08) 1px, transparent 1px), linear-gradient(90deg, rgba(148,163,184,.08) 1px, transparent 1px); background-size: 40px 40px; }
        @media (prefers-reduced-motion: reduce) { .blob, .ring-spin, .float, .shine, .caret::after { animation: none; } .rise { opacity: 1; animation: none; } }
    </style>
</head>
<body class="min-h-screen overflow-x-hidden bg-slate-950 text-white">
<div class="pointer-events-none fixed inset-0 grid-bg"></div>
<div class="pointer-events-none fixed -left-32 -top-32 size-[28rem] rounded-full bg-brand-500/30 blur-3xl blob"></div>
<div class="pointer-events-none fixed -right-32 top-1/3 size-[26rem] rounded-full bg-violet-600/30 blur-3xl blob" style="animation-delay:-5s"></div>
<div class="pointer-events-none fixed bottom-[-10rem] left-1/3 size-[24rem] rounded-full bg-rose-500/25 blur-3xl blob" style="animation-delay:-9s"></div>

<main class="relative mx-auto max-w-5xl px-5 py-10 sm:py-16">
    <div class="flex justify-between text-sm">
        <a href="{{ auth()->check() ? route(auth()->user()->homeRoute()) : route('login') }}" class="text-slate-400 hover:text-white">‹ Back to {{ \App\Models\Setting::get('business_name') ?: 'OpenRestoPro ERP' }}</a>
        <span class="text-slate-500">OpenRestoPro ERP</span>
    </div>

    {{-- Hero --}}
    <section class="mt-12 flex flex-col items-center text-center">
        <div class="relative rise" style="animation-delay:.05s">
            <div class="absolute -inset-1.5 rounded-full bg-[conic-gradient(from_0deg,#f97316,#f43f5e,#8b5cf6,#0ea5e9,#f97316)] ring-spin"></div>
            <div class="relative grid size-32 place-items-center rounded-full bg-slate-950 text-5xl font-black sm:size-36">
                <span class="bg-gradient-to-br from-brand-400 via-rose-400 to-violet-400 bg-clip-text text-transparent">M</span>
            </div>
            <span class="absolute bottom-2 right-2 flex size-5 items-center justify-center rounded-full bg-emerald-500 ring-4 ring-slate-950" title="Available for projects"><span class="size-2 animate-ping rounded-full bg-white"></span></span>
        </div>
        <p class="mt-8 text-sm font-semibold uppercase tracking-[.35em] text-brand-400 rise" style="animation-delay:.15s">Developed by</p>
        <h1 class="mt-2 text-5xl font-black tracking-tight sm:text-7xl rise" style="animation-delay:.25s">
            <span class="shine bg-gradient-to-r from-white via-brand-300 to-white bg-clip-text text-transparent">MANORANJAN</span>
        </h1>
        <p class="mt-4 h-8 text-xl font-semibold text-slate-200 sm:text-2xl rise" style="animation-delay:.35s"
           x-data="{ roles: ['Full-stack Developer', 'Android App Developer', 'IoT Engineer', 'PCB Designer', 'Founder, MTP Code'], i: 0, text: '', del: false,
                     tick() { const w = this.roles[this.i]; this.text = this.del ? w.slice(0, this.text.length - 1) : w.slice(0, this.text.length + 1);
                              if (!this.del && this.text === w) { this.del = true; return setTimeout(() => this.tick(), 1600) }
                              if (this.del && this.text === '') { this.del = false; this.i = (this.i + 1) % this.roles.length }
                              setTimeout(() => this.tick(), this.del ? 35 : 70) } }"
           x-init="tick()"><span x-text="text" class="caret"></span></p>
        <p class="mt-6 max-w-2xl text-base leading-relaxed text-slate-400 rise" style="animation-delay:.45s">
            I build software that runs real businesses and real hardware — web platforms, Android apps and smart IoT devices,
            from the circuit board to the cloud. OpenRestoPro ERP was built for restaurants, cake shops and hotels that want their
            counter, kitchen, staff and accounts in one simple place.
        </p>
        <div class="mt-8 flex flex-wrap justify-center gap-3 rise" style="animation-delay:.55s">
            <a href="https://manoranjan.dev" target="_blank" rel="noopener" class="group inline-flex items-center gap-2 rounded-2xl bg-gradient-to-r from-brand-500 to-rose-500 px-6 py-3 font-semibold shadow-lg shadow-brand-500/30 transition hover:-translate-y-0.5 hover:shadow-xl">
                🌐 manoranjan.dev <span class="transition group-hover:translate-x-1">→</span>
            </a>
            <a href="https://github.com/manoranjan2050" target="_blank" rel="noopener" class="group inline-flex items-center gap-2 rounded-2xl border border-white/15 bg-white/5 px-6 py-3 font-semibold backdrop-blur transition hover:-translate-y-0.5 hover:bg-white/10">
                <svg class="size-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5a12 12 0 0 0-3.8 23.4c.6.1.8-.3.8-.6v-2.2c-3.3.7-4-1.4-4-1.4-.6-1.4-1.4-1.8-1.4-1.8-1.1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1.1 1.8 2.8 1.3 3.5 1 .1-.8.4-1.3.8-1.6-2.7-.3-5.5-1.3-5.5-5.9 0-1.3.5-2.4 1.2-3.2-.1-.3-.5-1.5.1-3.2 0 0 1-.3 3.3 1.2a11.5 11.5 0 0 1 6 0C17.3 4.6 18.3 5 18.3 5c.6 1.7.2 2.9.1 3.2.8.8 1.2 1.9 1.2 3.2 0 4.6-2.8 5.6-5.5 5.9.4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6A12 12 0 0 0 12 .5Z"/></svg>
                github.com/manoranjan2050
            </a>
        </div>
    </section>

    {{-- Numbers --}}
    <section class="mt-16 grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach ([['10+', 'Android apps'], ['20+', 'IoT & PCB projects'], ['Full-stack', 'Laravel · Kotlin · Flutter'], ['MTP Code', 'own studio']] as $i => [$n, $l])
            <div class="float rounded-2xl border border-white/10 bg-white/5 p-5 text-center backdrop-blur rise" style="animation-delay: {{ .6 + $i * .1 }}s; animation-duration: .8s, 4s;">
                <p class="text-2xl font-black text-white sm:text-3xl">{{ $n }}</p>
                <p class="mt-1 text-xs uppercase tracking-wider text-slate-400">{{ $l }}</p>
            </div>
        @endforeach
    </section>

    {{-- What I do --}}
    <section class="mt-16">
        <h2 class="text-center text-sm font-semibold uppercase tracking-[.3em] text-slate-500">What I build</h2>
        <div class="mt-6 grid gap-4 md:grid-cols-3">
            @foreach ([
                ['💻', 'Web platforms', 'Business software like this ERP — Laravel, Livewire, Tailwind, REST APIs, payments, reports, cloud backups.', 'from-brand-500/20'],
                ['📱', 'Android apps', 'Native Kotlin + Jetpack Compose and Flutter apps, Bluetooth & USB hardware, published on Google Play.', 'from-violet-500/20'],
                ['🔌', 'IoT & PCB design', 'ESP32 devices, smart-home controllers, energy monitors and custom circuit boards from schematic to firmware.', 'from-sky-500/20'],
            ] as $i => [$icon, $t, $d, $tone])
                <div x-data="{ x: 0, y: 0 }" @mousemove="const r = $el.getBoundingClientRect(); x = (($event.clientX - r.left) / r.width - .5) * 10; y = (($event.clientY - r.top) / r.height - .5) * -10"
                     @mouseleave="x = 0; y = 0" :style="`transform: perspective(700px) rotateX(${y}deg) rotateY(${x}deg)`"
                     class="tilt rise rounded-3xl border border-white/10 bg-gradient-to-br {{ $tone }} to-transparent p-6 backdrop-blur hover:shadow-2xl hover:shadow-brand-500/10" style="animation-delay: {{ 1 + $i * .12 }}s">
                    <span class="text-3xl">{{ $icon }}</span>
                    <h3 class="mt-3 text-lg font-bold">{{ $t }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-400">{{ $d }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Skills marquee --}}
    <section class="mt-16 overflow-hidden rise" style="animation-delay:1.3s">
        <div class="flex w-max gap-3" x-data x-init="$el.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-50%)' }], { duration: 30000, iterations: Infinity })">
            @foreach (array_merge(...array_fill(0, 2, ['Laravel', 'PHP', 'Livewire', 'Tailwind CSS', 'Kotlin', 'Jetpack Compose', 'Flutter', 'MySQL', 'REST APIs', 'ESP32', 'ESPHome', 'KiCad', 'EasyEDA', 'Home Assistant', 'Bluetooth', 'Linux'])) as $s)
                <span class="whitespace-nowrap rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-300">{{ $s }}</span>
            @endforeach
        </div>
    </section>

    {{-- About this app --}}
    <section class="mt-16 rounded-3xl border border-white/10 bg-gradient-to-br from-white/10 to-white/0 p-6 text-center backdrop-blur sm:p-10 rise" style="animation-delay:1.4s">
        <p class="text-4xl">🍛</p>
        <h2 class="mt-3 text-2xl font-black">OpenRestoPro ERP</h2>
        <p class="mx-auto mt-3 max-w-2xl text-sm leading-relaxed text-slate-400">
            Sales counter with thermal printing, tables &amp; KOT, cake orders, GST bills, stock, expenses &amp; vendor khata,
            staff attendance &amp; salary, reports and cloud backups — on the web and on Android. Open source under the MIT licence.
        </p>
        <a href="https://github.com/manoranjan2050/RestoPro-ERP-Web" target="_blank" rel="noopener" class="mt-6 inline-flex items-center gap-2 text-sm font-semibold text-brand-400 hover:text-brand-300">⭐ View the project on GitHub →</a>
    </section>

    <p class="mt-12 text-center text-xs text-slate-500">© {{ now()->year }} MANORANJAN · <a href="https://manoranjan.dev" target="_blank" rel="noopener" class="hover:text-slate-300">manoranjan.dev</a></p>
</main>
@livewireScripts
</body>
</html>
