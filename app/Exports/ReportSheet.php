<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** One report section as a sheet: real numbers (not text) with ₹ formatting, bold header and totals row. */
class ReportSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private array $section) {}

    public function title(): string
    {
        return $this->section['title'];
    }

    public function headings(): array
    {
        return array_column($this->section['columns'], 0);
    }

    public function array(): array
    {
        $types = array_column($this->section['columns'], 1);
        $map = fn (array $row) => array_map(fn ($v, $t) => match (true) {
            $v === '' || $v === null => null,
            $t === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v) => ExcelDate::PHPToExcel(new \DateTime($v)),
            $t === 'pct' => (float) $v / 100,
            in_array($t, ['money', 'int', 'num'], true) => is_numeric($v) ? $v + 0 : $v,
            default => $v,
        }, $row, $types);

        $rows = array_map($map, $this->section['rows']);
        if (! empty($this->section['totals'])) {
            $rows[] = $map($this->section['totals']);
        }

        return $rows;
    }

    public function columnFormats(): array
    {
        $out = [];
        foreach ($this->section['columns'] as $i => [, $t]) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $out[$col] = match ($t) {
                'money' => '"₹"#,##0.00;[Red]-"₹"#,##0.00',
                'pct' => NumberFormat::FORMAT_PERCENTAGE_0,
                'date' => 'dd-mmm-yyyy',
                'int' => '0',
                'num' => '0.0#',
                default => NumberFormat::FORMAT_GENERAL,
            };
        }

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'EA580C']]]];
        if (! empty($this->section['totals'])) {
            $last = count($this->section['rows']) + 2;
            $styles[$last] = ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F1F5F9']]];
        }
        $sheet->freezePane('A2');

        return $styles;
    }
}
