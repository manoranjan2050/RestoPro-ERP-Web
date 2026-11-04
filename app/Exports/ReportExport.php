<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** One workbook per report: a Summary sheet with the KPIs, then one sheet per section. */
class ReportExport implements WithMultipleSheets
{
    public function __construct(private array $report, private string $business) {}

    public function sheets(): array
    {
        $summary = [
            'title' => 'Summary',
            'columns' => [['Item', 'text'], ['Value', 'text']],
            'rows' => array_merge(
                [['Business', $this->business], ['Report', $this->report['title']], ['Period', $this->report['subtitle']],
                    ['Generated', now()->format('d M Y, h:i A')], ['', '']],
                array_map(fn ($k) => [$k[0], in_array($k[2], ['money', 'int', 'num']) ? $k[1] : report_cell($k[1], $k[2])], $this->report['kpis']),
                [['', '']],
                array_map(fn ($n) => [$n, ''], $this->report['notes']),
            ),
            'totals' => null,
        ];

        $sheets = [new ReportSheet($summary)];
        $used = ['Summary' => true];
        foreach ($this->report['sections'] as $section) {
            // Excel sheet names: max 31 chars, unique, no []:*?/\
            $name = mb_substr(preg_replace('/[\[\]:*?\/\\\\]/', '', $section['title']), 0, 28);
            $base = $name;
            for ($i = 2; isset($used[$name]); $i++) {
                $name = $base.' '.$i;
            }
            $used[$name] = true;
            $sheets[] = new ReportSheet(['title' => $name] + $section);
        }

        return $sheets;
    }
}
