<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Services\Analytics;
use App\Services\Backup\BackupService;
use App\Services\MasterLedger;
use App\Services\ReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Read-mostly endpoints: master ledger, reports, annual summary, backups. */
class InsightsController extends Controller
{
    /** GET /ledger?from=&to=&source=&account=&direction= */
    public function ledger(Request $request)
    {
        $f = array_filter($request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'source' => ['nullable', Rule::in(array_keys(MasterLedger::SOURCES))],
            'account' => ['nullable', Rule::in(array_keys(MasterLedger::ACCOUNTS))],
            'direction' => ['nullable', 'in:in,out'],
        ]));
        $opening = MasterLedger::balanceBefore($f['from'], array_diff_key($f, ['direction' => 1]));
        $rows = MasterLedger::rows($f, $opening);

        return response()->json([
            'opening' => $opening,
            'closing' => round($opening + $rows->sum('in') - $rows->sum('out'), 2),
            'summary' => MasterLedger::summary($f),
            'accounts' => MasterLedger::accountBalances($f['to']),
            'data' => $rows->reverse()->values()->map(fn ($r) => array_merge(
                array_diff_key($r, ['url' => 1, 'key' => 1]), ['date' => $r['date']->toDateString()]
            )),
        ]);
    }

    /** GET /reports/{type}?month=|fy=|from=&to= — the same data the PDF/Excel use. */
    public function report(Request $request, string $type)
    {
        abort_unless(array_key_exists($type, ReportBuilder::types()), 404);
        $p = $request->validate(match (ReportBuilder::types()[$type][2]) {
            'month' => ['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']],
            'fy' => ['fy' => ['required', 'integer', 'between:2000,2100']],
            'range' => ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']],
            'day' => ['date' => ['required', 'date_format:Y-m-d']],
            default => [],
        });
        $report = ReportBuilder::build($type, $p);
        $report['downloads'] = [
            'pdf' => route('api.reports.download', ['type' => $type, 'format' => 'pdf'] + $p),
            'xlsx' => route('api.reports.download', ['type' => $type, 'format' => 'xlsx'] + $p),
        ];

        return response()->json($report);
    }

    /** GET /analytics?fy= */
    public function analytics(Request $request)
    {
        $years = Analytics::years();
        $fy = (int) ($request->fy ?? array_key_first($years));
        abort_unless(isset($years[$fy]), 404, 'Unknown financial year.');
        $a = Analytics::build($fy);
        $a['upto'] = $a['compare']['upto']->toDateString();
        $a['compare']['upto'] = $a['upto'];
        $day = fn ($c) => ['date' => $c->date->toDateString(), 'total' => (float) $c->total];
        $a['best_days'] = $a['best_days']->map($day)->values();
        $a['worst_days'] = $a['worst_days']->map($day)->values();
        $a['years'] = $years;

        return response()->json($a);
    }

    /** GET /backups (owner) */
    public function backups()
    {
        Gate::authorize('manage-backups');

        return response()->json(['data' => Backup::latest()->latest('id')->get()->map(fn (Backup $b) => [
            'id' => $b->id, 'filename' => $b->filename, 'size' => $b->size, 'trigger' => $b->trigger,
            'records' => $b->totalRows(), 'destinations' => $b->destinations, 'created_at' => $b->created_at->toIso8601String(),
        ])]);
    }

    /** POST /backups (owner) — back up now and upload to connected clouds. Restore is web-only on purpose. */
    public function backupNow(Request $request)
    {
        Gate::authorize('manage-backups');
        $b = BackupService::create('manual', $request->user()->id);

        return response()->json(['id' => $b->id, 'filename' => $b->filename, 'size' => $b->size, 'destinations' => $b->destinations], 201);
    }
}
