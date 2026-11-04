@php $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.'); @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('components.layouts.head', ['title' => ($title === 'BILL' ? 'Bill' : 'Tax invoice').' '.$o->number.' · '.$biz['name']])
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
</head>
<body class="min-h-screen">
<div class="mx-auto max-w-lg px-4 py-6 sm:py-10">
    <div class="mb-4 flex justify-end"><x-theme-toggle /></div>

    <div class="card overflow-hidden">
        <div class="bg-gradient-to-r from-brand-500 via-rose-500 to-violet-600 p-5 text-white">
            <div class="flex items-center gap-3">
                <x-brand-mark size="size-12" radius="rounded-2xl" class="ring-2 ring-white/40" />
                <div class="min-w-0">
                    <p class="truncate text-lg font-extrabold">{{ $biz['name'] }}</p>
                    @if ($biz['address'])<p class="truncate text-xs text-white/85">{{ $biz['address'] }}</p>@endif
                </div>
            </div>
            <div class="mt-5 flex items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-white/80">{{ $title === 'BILL' ? 'Bill' : 'Tax invoice' }}</p>
                    <p class="text-lg font-bold">{{ $o->number }}</p>
                    <p class="text-xs text-white/85">{{ $o->billed_at?->format('d M Y, h:i A') }}</p>
                </div>
                <p class="text-3xl font-extrabold tabular-nums">{{ money($o->total) }}</p>
            </div>
        </div>

        @if ($o->isCancelled())
            <p class="bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">This bill was cancelled.</p>
        @endif

        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($o->items as $l)
                <li class="flex items-start justify-between gap-3 px-5 py-3 text-sm">
                    <div>
                        <p class="font-semibold">{{ $l->label() }}</p>
                        <p class="text-xs text-slate-500">{{ $q($l->qty) }}{{ $l->unit !== 'pcs' ? ' '.$l->unit : '' }} × {{ money($l->rate) }}</p>
                        @if ($l->note)<p class="text-xs italic text-amber-600">“{{ $l->note }}”</p>@endif
                    </div>
                    <p class="font-semibold tabular-nums">{{ money($l->amount) }}</p>
                </li>
            @endforeach
        </ul>

        <div class="space-y-1 border-t border-slate-100 px-5 py-4 text-sm dark:border-slate-800">
            <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span class="tabular-nums">{{ money($o->subtotal) }}</span></div>
            @if ((float) $o->discount > 0)<div class="flex justify-between"><span class="text-slate-500">Discount</span><span class="tabular-nums text-emerald-600">−{{ money($o->discount) }}</span></div>@endif
            @if ($o->gst_enabled)
                <div class="flex justify-between text-xs"><span class="text-slate-500">CGST</span><span class="tabular-nums">{{ money($o->cgst) }}</span></div>
                <div class="flex justify-between text-xs"><span class="text-slate-500">SGST</span><span class="tabular-nums">{{ money($o->sgst) }}</span></div>
            @endif
            @if ((float) $o->round_off != 0)<div class="flex justify-between text-xs"><span class="text-slate-500">Round off</span><span class="tabular-nums">{{ number_format((float) $o->round_off, 2) }}</span></div>@endif
            <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-extrabold dark:border-slate-700"><span>Total</span><span class="tabular-nums">{{ money($o->total) }}</span></div>
            @foreach ($pays as $p)<div class="flex justify-between text-xs text-slate-500"><span>Paid by {{ $p['label'] }}</span><span class="tabular-nums">{{ money($p['amount']) }}</span></div>@endforeach
        </div>

        <div class="border-t border-slate-100 p-5 dark:border-slate-800">
            <a href="{{ route('bill.public.pdf', $o->share_token) }}" class="btn-primary w-full justify-center py-3"><x-icon name="doc" class="size-4" /> Download PDF</a>
            @if ($biz['phone'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $biz['phone']) }}" class="btn-ghost mt-2 w-full justify-center">Call {{ $biz['name'] }}</a>@endif
        </div>
    </div>

    @if ($print['footer'] !== '')<p class="mt-6 text-center text-sm text-slate-500">{!! nl2br(e($print['footer'])) !!}</p>@endif
    <p class="mt-2 text-center text-xs text-slate-400">{{ $o->gst_enabled && $biz['gstin'] ? 'GSTIN '.$biz['gstin'].' · ' : '' }}{{ $biz['fssai'] ? 'FSSAI '.$biz['fssai'] : '' }}</p>
</div>
@livewireScripts
</body>
</html>
