<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $report['title'] }} · {{ $report['subtitle'] }}</title>
<style>
    @page { margin: 28px 28px 40px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 9.5px; color: #1e293b; }
    .head { border-bottom: 3px solid #f97316; padding-bottom: 8px; margin-bottom: 12px; }
    .biz { font-size: 15px; font-weight: bold; color: #0f172a; }
    .logo { float: left; width: 44px; height: 44px; margin-right: 10px; border-radius: 6px; }
    .contact { color: #64748b; font-size: 8px; margin-top: 1px; }
    .title { font-size: 12px; font-weight: bold; color: #ea580c; margin-top: 2px; }
    .sub { color: #64748b; margin-top: 2px; }
    .gen { float: right; color: #94a3b8; font-size: 8px; text-align: right; }
    table.kpi { width: 100%; border-collapse: separate; border-spacing: 5px; margin: 0 -5px 8px; }
    table.kpi td { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 6px; padding: 6px 8px; width: 16%; }
    .kl { color: #9a3412; font-size: 8px; }
    .kv { font-size: 12px; font-weight: bold; margin-top: 2px; }
    h3 { font-size: 10.5px; margin: 14px 0 4px; color: #0f172a; }
    table.d { width: 100%; border-collapse: collapse; }
    table.d th { background: #f1f5f9; color: #475569; font-size: 8px; text-transform: uppercase; padding: 5px 5px; border-bottom: 1px solid #cbd5e1; }
    table.d td { padding: 4px 5px; border-bottom: 1px solid #e2e8f0; }
    table.d tr:nth-child(even) td { background: #fafafa; }
    table.d tfoot td { font-weight: bold; background: #f1f5f9; border-top: 1.5px solid #94a3b8; }
    .r { text-align: right; white-space: nowrap; }
    .neg { color: #e11d48; }
    .note { color: #64748b; font-size: 8px; margin-top: 3px; }
    .empty { color: #94a3b8; padding: 8px 0; }
    .foot { position: fixed; bottom: -26px; left: 0; right: 0; color: #94a3b8; font-size: 7.5px; text-align: center; }
</style>
</head>
<body>
<div class="foot">{{ $business }} · {{ $report['title'] }} · {{ $report['subtitle'] }} · generated {{ now()->format('d M Y, h:i A') }}</div>

<div class="head">
    <div class="gen">Generated<br>{{ now()->format('d M Y, h:i A') }}</div>
    @php
        $logoPath = \App\Models\Setting::get('business_logo');
        $logoData = $logoPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($logoPath)
            ? 'data:'.\Illuminate\Support\Facades\Storage::disk('public')->mimeType($logoPath).';base64,'.base64_encode(\Illuminate\Support\Facades\Storage::disk('public')->get($logoPath))
            : null;
        $contact = collect([
            \App\Models\Setting::get('business_address'),
            \App\Models\Setting::get('business_phone') ? 'Ph: '.\App\Models\Setting::get('business_phone') : null,
            \App\Models\Setting::get('business_email'),
            \App\Models\Setting::get('business_gstin') ? 'GSTIN: '.\App\Models\Setting::get('business_gstin') : null,
            \App\Models\Setting::get('business_fssai') ? 'FSSAI: '.\App\Models\Setting::get('business_fssai') : null,
        ])->filter()->implode(' · ');
    @endphp
    @if ($logoData) <img class="logo" src="{{ $logoData }}" alt=""> @endif
    <div class="biz">{{ $business }}</div>
    @if ($contact) <div class="contact">{{ $contact }}</div> @endif
    <div class="title">{{ $report['title'] }}</div>
    <div class="sub">{{ $report['subtitle'] }}</div>
    <div style="clear: both"></div>
</div>

<table class="kpi">
    @foreach (array_chunk($report['kpis'], 6) as $chunk)
        <tr>
            @foreach ($chunk as [$label, $value, $t])
                <td><div class="kl">{{ $label }}</div><div class="kv {{ is_numeric($value) && $value < 0 ? 'neg' : '' }}">{{ report_cell($value, $t) ?: '—' }}</div></td>
            @endforeach
        </tr>
    @endforeach
</table>

@foreach ($report['sections'] as $section)
    <h3>{{ $section['title'] }}</h3>
    @if (empty($section['rows']))
        <div class="empty">Nothing in this period.</div>
    @else
        <table class="d">
            <thead>
                <tr>
                    @foreach ($section['columns'] as [$col, $t])
                        <th class="{{ in_array($t, ['text', 'date']) ? '' : 'r' }}" style="text-align: {{ in_array($t, ['text', 'date']) ? 'left' : 'right' }}">{{ $col }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($section['rows'] as $row)
                    <tr>
                        @foreach ($section['columns'] as $i => [$col, $t])
                            <td class="{{ in_array($t, ['text', 'date']) ? '' : 'r' }} {{ $t === 'money' && is_numeric($row[$i]) && $row[$i] < 0 ? 'neg' : '' }}">{{ report_cell($row[$i], $t) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @if ($section['totals'])
                <tfoot>
                    <tr>
                        @foreach ($section['columns'] as $i => [$col, $t])
                            <td class="{{ in_array($t, ['text', 'date']) ? '' : 'r' }}">{{ report_cell($section['totals'][$i], $t) }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    @endif
@endforeach

@foreach ($report['notes'] as $n)
    <div class="note">• {{ $n }}</div>
@endforeach
</body>
</html>
