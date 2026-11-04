<?php

namespace App\Http\Controllers;

use App\Exports\ReportExport;
use App\Models\Setting;
use App\Services\ReportBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class ReportDownloadController extends Controller
{
    public function __invoke(Request $request, string $type, string $format)
    {
        abort_unless(array_key_exists($type, ReportBuilder::TYPES) && in_array($format, ['pdf', 'xlsx'], true), 404);

        $kind = ReportBuilder::TYPES[$type][2];
        $p = $request->validate(match ($kind) {
            'month' => ['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']],
            'fy' => ['fy' => ['required', 'integer', 'between:2000,2100']],
            'range' => ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']],
            default => [],
        });

        $report = ReportBuilder::build($type, $p);
        $business = (string) Setting::get('business_name');
        $file = Str::slug($business.' '.$report['title'].' '.$report['subtitle']);

        if ($format === 'xlsx') {
            return Excel::download(new ReportExport($report, $business), $file.'.xlsx');
        }

        // Wide tables (salary sheet, vendor dues, bill lists) print better in landscape.
        $maxCols = max(array_map(fn ($s) => count($s['columns']), $report['sections']) ?: [1]);

        $pdf = Pdf::loadView('reports.pdf', ['report' => $report, 'business' => $business])
            ->setPaper('a4', $maxCols > 7 ? 'landscape' : 'portrait')
            ->setOption('isFontSubsettingEnabled', true); // embed only used glyphs: ~900 KB → tens of KB

        // ?view=1 opens it in the browser (to read or print) instead of downloading.
        return $request->boolean('view') ? $pdf->stream($file.'.pdf') : $pdf->download($file.'.pdf');
    }
}
