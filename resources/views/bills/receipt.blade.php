@php
    $w = $print['width'];
    $paper = $w === '58' ? 58 : 80;
    $inner = $w === '58' ? 48 : 72;          // printable width of common thermal printers (mm)
    $fs = $w === '58' ? 10.5 : 12;           // px
    $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $m = fn ($v) => inr_group((float) $v, fmod(abs((float) $v), 1) > 0.004 ? 2 : 0);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $o->ref() }}</title>
<style>
    @page { size: {{ $paper }}mm auto; margin: 0; }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #fff; color: #000; }
    body { font: {{ $fs }}px/1.3 Arial, "Helvetica Neue", Helvetica, sans-serif; }
    .r { width: {{ $inner }}mm; margin: 0 auto; padding: 3mm 0 6mm; }
    .c { text-align: center; }
    .b { font-weight: 700; }
    .big { font-size: 1.35em; }
    .sm { font-size: .85em; }
    .logo { display: block; max-width: 22mm; max-height: 22mm; margin: 0 auto 1.5mm; filter: grayscale(1) contrast(1.2); }
    .hr { border-top: 1px dashed #000; margin: 1.6mm 0; }
    .hr2 { border-top: 2px solid #000; margin: 1.6mm 0; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: .35mm 0; vertical-align: top; }
    th { font-weight: 700; text-align: left; border-bottom: 1px dashed #000; }
    .n { text-align: right; white-space: nowrap; }
    .row { display: flex; justify-content: space-between; gap: 2mm; }
    .note { font-style: italic; font-size: .85em; }
    .tot { font-size: 1.4em; font-weight: 800; }
    .stamp { border: 2px solid #000; padding: 1mm; text-align: center; font-weight: 800; margin: 2mm 0; }
    @media screen {
        body { background: #e2e8f0; }
        .r { background: #fff; margin: 12px auto; padding: 5mm 4mm 8mm; width: {{ $paper }}mm; box-shadow: 0 4px 20px rgba(0,0,0,.15); }
        .bar { position: sticky; top: 0; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #0f172a; }
        .bar a, .bar button { font: 600 13px Arial; color: #fff; background: #f97316; border: 0; border-radius: 8px; padding: 8px 14px; text-decoration: none; cursor: pointer; }
        .bar a.ghost { background: #334155; }
    }
    @media print { .bar { display: none; } }
</style>
</head>
<body>
@unless ($auto)
    <div class="bar">
        <button onclick="window.print()">Print</button>
        <a class="ghost" href="?w={{ $w === '58' ? '80' : '58' }}">{{ $w === '58' ? '80 mm' : '58 mm' }} paper</a>
        <a class="ghost" href="{{ route('orders.show', $o) }}">Back to bill</a>
    </div>
@endunless
<div class="r">
    @if ($print['logo'] && $biz['logo_url'])<img class="logo" src="{{ $biz['logo_url'] }}" alt="">@endif
    <div class="c b big">{{ $biz['name'] }}</div>
    @if ($biz['tagline'])<div class="c sm">{{ $biz['tagline'] }}</div>@endif
    @if ($biz['address'])<div class="c sm">{{ $biz['address'] }}</div>@endif
    @if ($biz['phone'])<div class="c sm">Ph: {{ $biz['phone'] }}</div>@endif
    @if ($o->gst_enabled && $biz['gstin'])<div class="c sm b">GSTIN: {{ $biz['gstin'] }}</div>@endif
    @if ($biz['fssai'])<div class="c sm">FSSAI: {{ $biz['fssai'] }}</div>@endif

    <div class="hr2"></div>
    <div class="c b">{{ $title }}</div>
    @if ($o->isCancelled())<div class="stamp">CANCELLED</div>@endif
    <div class="row sm"><span>{{ $o->number ? 'Bill' : 'Order' }}: <b>{{ $o->number ?? '#'.$o->id }}</b></span><span>{{ \App\Models\Order::TYPES[$o->type] ?? '' }}</span></div>
    <div class="row sm"><span>{{ $o->billed_at?->format('d/m/Y h:i A') }}</span>@if ($o->creator)<span>{{ $o->creator->name }}</span>@endif</div>
    @if ($o->table)<div class="sm">Table: <b>{{ $o->table->name }}</b>{{ $o->guests ? ' · '.$o->guests.' guests' : '' }}</div>@endif
    @if ($o->due_at)<div class="b">Pickup: {{ $o->due_at->format('D d M Y, h:i A') }}</div>@endif
    @if ($o->customer_name || $o->customer_mobile)
        <div class="sm">Customer: {{ $o->customer_name }}{{ $o->customer_mobile ? ' · '.$o->customer_mobile : '' }}</div>
        @if ($o->customer_gstin)<div class="sm">Cust. GSTIN: {{ $o->customer_gstin }}</div>@endif
    @endif

    @if ($o->delivery_address)<div class="sm">Deliver to: {{ $o->delivery_address }}</div>@endif
    @if ($o->details)
        <div class="hr"></div>
        @foreach (App\Models\Order::CAKE_FIELDS as $k => $label)
            @if (! empty($o->details[$k]))<div class="sm"><b>{{ $label }}:</b> {{ $k === 'eggless' ? 'Yes' : $o->details[$k] }}</div>@endif
        @endforeach
    @endif
    <div class="hr"></div>
    <table>
        <thead><tr><th>Item</th><th class="n">Qty</th><th class="n">Amt</th></tr></thead>
        <tbody>
        @foreach ($o->items as $l)
            <tr>
                <td colspan="3" class="b" style="padding-top:1mm">{{ $l->label() }}</td>
            </tr>
            <tr>
                <td class="sm">{{ $m($l->rate) }}{{ $o->gst_enabled ? ' · '.(float) $l->gst_rate.'%' : '' }}{{ $o->gst_enabled && $l->hsn ? ' · HSN '.$l->hsn : '' }}</td>
                <td class="n">{{ $q($l->qty) }}{{ $l->unit !== 'pcs' ? ' '.$l->unit : '' }}</td>
                <td class="n">{{ $m($l->amount) }}</td>
            </tr>
            @if ($l->note)<tr><td colspan="3" class="note">* {{ $l->note }}</td></tr>@endif
        @endforeach
        </tbody>
    </table>

    <div class="hr"></div>
    <div class="row"><span>Subtotal ({{ $q($o->items->sum('qty')) }} qty)</span><span>{{ $m($o->subtotal) }}</span></div>
    @if ((float) $o->discount > 0)
        <div class="row"><span>Discount{{ $o->discount_type === 'pct' ? ' '.(float) $o->discount_value.'%' : '' }}</span><span>-{{ $m($o->discount) }}</span></div>
    @endif
    @if ($o->gst_enabled)
        <div class="row sm"><span>Taxable value</span><span>{{ $m($o->taxable) }}</span></div>
        <div class="row sm"><span>CGST</span><span>{{ $m($o->cgst) }}</span></div>
        <div class="row sm"><span>SGST</span><span>{{ $m($o->sgst) }}</span></div>
    @endif
    @if ((float) $o->delivery_charge > 0)<div class="row"><span>Delivery</span><span>{{ $m($o->delivery_charge) }}</span></div>@endif
    @if ((float) $o->round_off != 0)<div class="row sm"><span>Round off</span><span>{{ number_format((float) $o->round_off, 2) }}</span></div>@endif
    <div class="hr2"></div>
    <div class="row tot"><span>TOTAL</span><span>{{ \App\Models\Setting::get('currency_symbol', '₹') }}{{ $m($o->total) }}</span></div>
    <div class="hr2"></div>
    @foreach ($pays as $p)
        <div class="row sm"><span>Paid by {{ $p['label'] }}</span><span>{{ $m($p['amount']) }}</span></div>
    @endforeach
    @if ($o->balance() > 0.009)<div class="row b"><span>Balance due</span><span>{{ $m($o->balance()) }}</span></div>@endif

    @if ($o->gst_enabled && $byRate->count())
        <div class="hr"></div>
        <table class="sm">
            <thead><tr><th>GST%</th><th class="n">Taxable</th><th class="n">CGST</th><th class="n">SGST</th></tr></thead>
            <tbody>
            @foreach ($byRate as $r => $v)
                <tr><td>{{ $r }}%</td><td class="n">{{ number_format($v['taxable'], 2) }}</td><td class="n">{{ number_format($v['tax'] / 2, 2) }}</td><td class="n">{{ number_format($v['tax'] / 2, 2) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
    @if ($o->note)<div class="hr"></div><div class="sm">Note: {{ $o->note }}</div>@endif

    @if ($print['footer'] !== '')
        <div class="hr"></div>
        <div class="c">{!! nl2br(e($print['footer'])) !!}</div>
    @endif
</div>
@if ($auto)
<script>
    // Opened inside the POS's hidden frame: print as soon as the logo has loaded.
    window.addEventListener('load', function () { setTimeout(function () { window.focus(); window.print(); }, 150); });
</script>
@endif
</body>
</html>
