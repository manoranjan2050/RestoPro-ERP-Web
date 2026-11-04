<?php

namespace App\Http\Controllers;

use App\Services\MasterLedger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LedgerExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $f = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'source' => ['nullable', Rule::in(array_keys(MasterLedger::SOURCES))],
            'account' => ['nullable', Rule::in(array_keys(MasterLedger::ACCOUNTS))],
            'direction' => ['nullable', 'in:in,out'],
        ]);
        $f = array_filter($f);

        $opening = MasterLedger::balanceBefore($f['from'], array_diff_key($f, ['direction' => 1]));
        $rows = MasterLedger::rows($f, $opening);

        return response()->streamDownload(function () use ($rows, $opening) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Type', 'Particulars', 'Details', 'Account', 'Mode', 'In', 'Out', 'Balance']);
            fputcsv($out, ['', 'Opening', 'Opening balance', '', '', '', '', '', $opening]);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['date']->format('Y-m-d'), MasterLedger::SOURCES[$r['source']], $r['title'], $r['sub'],
                    MasterLedger::ACCOUNTS[$r['account']], $r['mode'], $r['in'] ?: '', $r['out'] ?: '', $r['balance'],
                ]);
            }
            fputcsv($out, ['', 'Total', '', '', '', '', $rows->sum('in'), $rows->sum('out'), $opening + $rows->sum('in') - $rows->sum('out')]);
            fclose($out);
        }, "ledger_{$f['from']}_to_{$f['to']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
