<?php

namespace App\Http\Controllers;

use App\Services\RevenueReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RevenueExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $v = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group' => ['nullable', 'in:day,week,month'],
        ]);

        $report = new RevenueReport(Carbon::parse($v['from']), Carbon::parse($v['to']), $v['group'] ?? 'day');
        $rows = $report->rows()->sortBy('key');
        $totals = $report->totals();
        $name = "revenue_{$v['from']}_to_{$v['to']}.csv";

        return response()->streamDownload(function () use ($rows, $totals) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it cleanly
            fputcsv($out, ['Period', 'Days', 'Cash', 'UPI', 'Online', 'Total']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['label'], $r['days'], $r['cash'], $r['upi'], $r['online'], $r['total']]);
            }
            fputcsv($out, ['TOTAL', $totals['days'], $totals['cash'], $totals['upi'], $totals['online'], $totals['total']]);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
