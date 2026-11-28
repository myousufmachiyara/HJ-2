<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class SkuLookupExport implements FromArray, WithHeadings, ShouldAutoSize, WithColumnFormatting
{
    public const HEADINGS = ['List No', 'Searched Name', 'Match', 'Product Name', 'Brand', 'Category', 'Product SKU',
                             'Variation SKU', 'Variation', 'Barcode', 'Price Before Discount', 'Price After Discount'];

    public function __construct(private array $rows) {}

    public function array(): array { return $this->rows; }
    public function headings(): array { return self::HEADINGS; }

    public function columnFormats(): array
    {
        return [
            'G' => NumberFormat::FORMAT_TEXT,
            'H' => NumberFormat::FORMAT_TEXT,
            'J' => NumberFormat::FORMAT_TEXT,
            'K' => NumberFormat::FORMAT_NUMBER_00,
            'L' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }
}
