@php $apk = \App\Support\AndroidApp::info(); @endphp
{{-- "Get the Android app" card (Dashboard + Settings). Shows nothing until the owner uploads the APK. --}}
@if ($apk)
    <div {{ $attributes->merge(['class' => 'relative overflow-clip rounded-3xl bg-gradient-to-br from-emerald-500 via-teal-500 to-sky-600 p-5 text-white shadow-lg shadow-teal-500/20 sm:p-6']) }}>
        <div class="pointer-events-none absolute -right-10 -top-10 size-40 rounded-full bg-white/10"></div>
        <div class="relative flex flex-wrap items-center gap-4">
            <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-white/20 text-3xl">📱</span>
            <div class="min-w-0 flex-1">
                <p class="text-lg font-bold">Get the Android app</p>
                <p class="text-sm text-white/85">
                    OpenRestoPro ERP {{ $apk['version'] ? 'v'.$apk['version'] : '' }} · {{ \App\Support\AndroidApp::sizeLabel($apk['size']) }}
                    @if ($apk['uploaded_at']) · updated {{ \Illuminate\Support\Carbon::parse($apk['uploaded_at'])->format('d M Y') }}@endif
                </p>
                <p class="mt-1 text-xs text-white/75">Open this page on the phone, tap Download, then open the file. Android asks once to “allow installs from this source”.</p>
            </div>
            <a href="{{ route('app.download') }}" class="shrink-0 rounded-xl bg-white px-5 py-2.5 text-sm font-bold text-teal-700 shadow hover:bg-teal-50">⬇ Download APK</a>
        </div>
    </div>
@endif
