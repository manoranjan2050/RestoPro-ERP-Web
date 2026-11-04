@php
    $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $cur = \App\Models\Setting::get('currency_symbol', '₹');
    $m = fn ($v) => $cur.inr_group((float) $v, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $title }} {{ $o->number }}</title>
<style>
    @page { margin: 22px 24px 30px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 9px; color: #1e293b; }
    .head { border-bottom: 3px solid #f97316; padding-bottom: 8px; }
    .logo { float: left; width: 46px; height: 46px; margin-right: 10px; border-radius: 6px; }
    .biz { font-size: 14px; font-weight: bold; color: #0f172a; }
    .muted { color: #64748b; }
    .title { float: right; text-align: right; }
    .title .t { font-size: 12px; font-weight: bold; color: #ea580c; letter-spacing: 1px; }
    .title .no { font-size: 11px; font-weight: bold; margin-top: 2px; }
    .clear { clear: both; }
    .box { margin-top: 10px; }
    table.d { width: 100%; border-collapse: collapse; margin-top: 10px; }
    table.d th { background: #f1f5f9; color: #475569; font-size: 7.5px; text-transform: uppercase; padding: 5px; border-bottom: 1px solid #cbd5e1; text-align: left; }
    table.d td { padding: 5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .r { text-align: right; white-space: nowrap; }
    .note { color: #b45309; font-style: italic; font-size: 8px; }
    table.t { width: 55%; margin-left: 45%; border-collapse: collapse; margin-top: 8px; }
    table.t td { padding: 3px 5px; }
    table.t tr.grand td { font-size: 12px; font-weight: bold; border-top: 2px solid #0f172a; padding-top: 6px; }
    .stamp { margin-top: 8px; padding: 6px; border: 2px solid #e11d48; color: #e11d48; font-weight: bold; text-align: center; font-size: 11px; }
    .foot { position: fixed; bottom: -18px; left: 0; right: 0; text-align: center; color: #94a3b8; font-size: 7px; }
</style>
</head>
<body>
<div class="foot">{{ $biz['name'] }} · {{ $o->number }} · computer-generated {{ strtolower($title) }}</div>

<div class="head">
    <div class="title">
        <div class="t">{{ $title }}</div>
        <div class="no">{{ $o->number }}</div>
        <div class="muted">{{ $o->billed_at?->format('d M Y, h:i A') }}</div>
    </div>
    @if ($biz['logo_data'])<img class="logo" src="{{ $biz['logo_data'] }}" alt="">@endif
    <div class="biz">{{ $biz['name'] }}</div>
    <div class="muted">{{ collect([$biz['address'], $biz['phone'] ? 'Ph: '.$biz['phone'] : null, $biz['email']])->filter()->implode(' · ') }}</div>
    <div class="muted">{{ collect([$o->gst_enabled && $biz['gstin'] ? 'GSTIN: '.$biz['gstin'] : null, $biz['fssai'] ? 'FSSAI: '.$biz['fssai'] : null])->filter()->implode(' · ') }}</div>
    <div class="clear"></div>
</div>

@if ($o->isCancelled())<div class="stamp">CANCELLED — {{ $o->cancel_reason }}</div>@endif

@if ($o->customer_name || $o->customer_mobile)
    <div class="box"><span class="muted">Billed to:</span> <b>{{ $o->customer_name }}</b>{{ $o->customer_mobile ? ' · '.$o->customer_mobile : '' }}{{ $o->customer_gstin ? ' · GSTIN '.$o->customer_gstin : '' }}</div>
@endif

<table class="d">
    <thead>
        <tr>
            <th style="width:18px">#</th><th>Item</th>
            @if ($o->gst_enabled)<th>HSN</th>@endif
            <th class="r">Qty</th><th class="r">Rate</th>
            @if ($o->gst_enabled)<th class="r">GST</th>@endif
            <th class="r">Amount</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($o->items as $i => $l)
        <tr>
            <td class="muted">{{ $i + 1 }}</td>
            <td>{{ $l->label() }}@if ($l->note)<div class="note">{{ $l->note }}</div>@endif</td>
            @if ($o->gst_enabled)<td>{{ $l->hsn }}</td>@endif
            <td class="r">{{ $q($l->qty) }}{{ $l->unit !== 'pcs' ? ' '.$l->unit : '' }}</td>
            <td class="r">{{ $m($l->rate) }}</td>
            @if ($o->gst_enabled)<td class="r">{{ (float) $l->gst_rate }}%</td>@endif
            <td class="r">{{ $m($l->amount) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="t">
    <tr><td class="muted">Subtotal</td><td class="r">{{ $m($o->subtotal) }}</td></tr>
    @if ((float) $o->discount > 0)<tr><td class="muted">Discount{{ $o->discount_type === 'pct' ? ' ('.(float) $o->discount_value.'%)' : '' }}</td><td class="r">−{{ $m($o->discount) }}</td></tr>@endif
    @if ($o->gst_enabled)
        <tr><td class="muted">Taxable value</td><td class="r">{{ $m($o->taxable) }}</td></tr>
        <tr><td class="muted">CGST</td><td class="r">{{ $m($o->cgst) }}</td></tr>
        <tr><td class="muted">SGST</td><td class="r">{{ $m($o->sgst) }}</td></tr>
    @endif
    @if ((float) $o->delivery_charge > 0)<tr><td class="muted">Delivery</td><td class="r">{{ $m($o->delivery_charge) }}</td></tr>@endif
    @if ((float) $o->round_off != 0)<tr><td class="muted">Round off</td><td class="r">{{ number_format((float) $o->round_off, 2) }}</td></tr>@endif
    <tr class="grand"><td>Total</td><td class="r">{{ $m($o->total) }}</td></tr>
    @foreach ($pays as $p)<tr><td class="muted">Paid by {{ $p['label'] }}</td><td class="r">{{ $m($p['amount']) }}</td></tr>@endforeach
    @if ($o->balance() > 0.009)<tr><td><b>Balance due</b></td><td class="r"><b>{{ $m($o->balance()) }}</b></td></tr>@endif
</table>

@if ($o->gst_enabled && $byRate->count())
    <table class="d" style="width:70%">
        <thead><tr><th>GST rate</th><th class="r">Taxable</th><th class="r">CGST</th><th class="r">SGST</th><th class="r">Total tax</th></tr></thead>
        <tbody>
        @foreach ($byRate as $r => $v)
            <tr><td>{{ $r }}%</td><td class="r">{{ $m($v['taxable']) }}</td><td class="r">{{ $m($v['tax'] / 2) }}</td><td class="r">{{ $m($v['tax'] / 2) }}</td><td class="r">{{ $m($v['tax']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endif

@if ($print['footer'] !== '')<p class="muted" style="margin-top:14px;text-align:center">{!! nl2br(e($print['footer'])) !!}</p>@endif
</body>
</html>
