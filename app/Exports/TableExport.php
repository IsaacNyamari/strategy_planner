<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithFreezePane;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * A simple styled sheet: bold, shaded header row, frozen, auto-sized columns.
 * Used for the songs list, uniform timetable and ministers roster.
 */
class TableExport implements FromArray, ShouldAutoSize, WithFreezePane, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  array<string, string>  $columns  row key => column heading (only these are exported, in this order)
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function __construct(
        protected string $title,
        protected array $columns,
        protected array $rows,
    ) {}

    public function array(): array
    {
        return array_map(
            fn (array $row) => array_map(fn (string $key) => $row[$key] ?? '', array_keys($this->columns)),
            $this->rows,
        );
    }

    public function headings(): array
    {
        return array_values($this->columns);
    }

    public function title(): string
    {
        // Excel sheet names: max 31 chars, no []:*?/\
        return mb_substr(preg_replace('/[\[\]:*?\/\\\\]/', '', $this->title) ?? 'Sheet', 0, 31);
    }

    public function freezePane(): string
    {
        return 'A2';
    }

    public function styles(Worksheet $sheet): ?array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '4338CA']],
            ],
        ];
    }
}
