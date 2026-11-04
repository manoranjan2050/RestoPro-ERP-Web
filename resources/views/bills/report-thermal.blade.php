@php
    $w = $print['width'];
    $paper = $w === '58' ? 58 : 80;
    $inner = $w === '58' ? 48 : 72;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title>{{ $report['title'] }} · {{ $report['subtitle'] }}</title>
<style>
    @page { size: {{ $paper }}mm auto; margin: 0; }
    html, body { margin: 0; background: #fff; color: #000; }
    body { font: {{ $w === '58' ? 10.5 : 12 }}px/1.3 Arial, sans-serif; }
    .r { width: {{ $inner }}mm; margin: 0 auto; padding: 3mm 0 8mm; }
    .c { text-align: center; } .b { font-weight: 700; }
    .hr { border-top: 1px dashed #000; margin: 1.6mm 0; }
    .hr2 { border-top: 2px solid #000; margin: 1.6mm 0; }
    .row { display: flex; justify-content: space-between; gap: 2mm; }
    h3 { font-size: 1em; margin: 2mm 0 1mm; text-transform: uppercase; }
    @media screen { body { background: #e2e8f0; } .r { background: #fff; margin: 12px auto; padding: 5mm 4mm; width: {{ $paper }}mm; }
        .bar { display: flex; justify-content: center; padding: 10px; background: #0f172a; } .bar button { font: 600 13px Arial; color: #fff; background: #f97316; border: 0; border-radius: 8px; padding: 8px 14px; cursor: pointer; } }
    @media print { .bar { display: none; } }
</style>
</head>
<body>
<div class="bar"><button onclick="window.print()">Print</button></div>
<div class="r">
    <div class="c b" style="font-size:1.3em">{{ $business }}</div>
    <div class="c b">{{ strtoupper($report['title']) }}</div>
    <div class="c">{{ $report['subtitle'] }}</div>
    <div class="c" style="font-size:.85em">Printed {{ now()->format('d/m/Y h:i A') }}</div>
    <div class="hr2"></div>
    @foreach ($report['kpis'] as [$label, $value, $type])
        <div class="row"><span>{{ $label }}</span><span class="b">{{ report_cell($value, $type) }}</span></div>
    @endforeach
    @foreach ($report['sections'] as $s)
        <div class="hr"></div>
        <h3>{{ $s['title'] }}</h3>
        @forelse ($s['rows'] as $row)
            {{-- first column = label, last column = the figure that matters --}}
            <div class="row"><span>{{ report_cell($row[0], $s['columns'][0][1]) }}{{ count($row) > 2 && $s['columns'][1][1] !== 'money' ? ' · '.report_cell($row[1], $s['columns'][1][1]) : '' }}</span>
                <span>{{ report_cell(end($row), end($s['columns'])[1]) }}</span></div>
        @empty
            <div>—</div>
        @endforelse
        @if ($s['totals'])
            <div class="row b" style="border-top:1px solid #000;margin-top:1mm;padding-top:.5mm"><span>{{ $s['totals'][0] }}</span><span>{{ report_cell(end($s['totals']), end($s['columns'])[1]) }}</span></div>
        @endif
    @endforeach
    <div class="hr2"></div>
    @foreach ($report['notes'] as $n)<div style="font-size:.85em">{{ $n }}</div>@endforeach
    <div class="c" style="margin-top:4mm">Signature ____________</div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 200); });</script>
</body>
</html>
