@php
    $w = $print['width'];
    $paper = $w === '58' ? 58 : 80;
    $inner = $w === '58' ? 48 : 72;
    $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title>KOT {{ $o->number ?? '#'.$o->id }}</title>
<style>
    @page { size: {{ $paper }}mm auto; margin: 0; }
    html, body { margin: 0; background: #fff; color: #000; }
    body { font: {{ $w === '58' ? 12 : 14 }}px/1.35 Arial, sans-serif; }
    .r { width: {{ $inner }}mm; margin: 0 auto; padding: 3mm 0 8mm; }
    .c { text-align: center; } .b { font-weight: 800; }
    .hr { border-top: 2px dashed #000; margin: 2mm 0; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 1mm 0; vertical-align: top; }
    .qty { width: 12mm; font-size: 1.3em; font-weight: 800; }
    .note { font-style: italic; }
    @media screen { body { background: #e2e8f0; } .r { background: #fff; margin: 12px auto; padding: 5mm 4mm; width: {{ $paper }}mm; } }
</style>
</head>
<body>
<div class="r">
    <div class="c b" style="font-size:1.4em">KITCHEN ORDER</div>
    <div class="c">{{ $o->number ?? 'Order #'.$o->id }} · {{ \App\Models\Order::TYPES[$o->type] ?? '' }}</div>
    @if ($o->table)<div class="c b" style="font-size:1.6em">{{ $o->table->name }}</div>@endif
    @isset($kotNo)<div class="c b">KOT {{ $kotNo }}</div>@endisset
    <div class="c">{{ now()->format('d/m h:i A') }}{{ $o->customer_name ? ' · '.$o->customer_name : '' }}</div>
    @if ($o->due_at)<div class="c b">Pickup {{ $o->due_at->format('D d M, h:i A') }}</div>@endif
    @if ($o->details)@foreach (App\Models\Order::CAKE_FIELDS as $k => $label)@if (! empty($o->details[$k]))<div><b>{{ $label }}:</b> {{ $k === 'eggless' ? 'Yes' : $o->details[$k] }}</div>@endif @endforeach @endif
    <div class="hr"></div>
    <table>
        @foreach ($o->items as $l)
            <tr>
                <td class="qty">{{ $q($l->qty) }}×</td>
                <td><span class="b">{{ $l->label() }}</span>@if ($l->note)<div class="note">» {{ $l->note }}</div>@endif</td>
            </tr>
        @endforeach
    </table>
    @if ($o->note)<div class="hr"></div><div class="b">Note: {{ $o->note }}</div>@endif
    <div class="hr"></div>
</div>
@if ($auto)<script>window.addEventListener('load', function () { setTimeout(function () { window.focus(); window.print(); }, 150); });</script>@endif
</body>
</html>
