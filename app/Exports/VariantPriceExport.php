<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Bulk price sheet:  Variant SKU | Variant Price | Variant Discount | Sell Ahead
 * Rows are [sku, price, discount, ''] — Sell Ahead is always left empty.
 */
class VariantPriceExport implements FromArray, WithHeadings, ShouldAutoSize, WithColumnFormatting
{
    public function __construct(private array $rows) {}

    public function array(): array
    {
        return array_map(fn ($r) => [
            (string) $r[0],
            $r[1] !== null ? (float) $r[1] : '',
            $r[2] !== null ? (float) $r[2] : '',
            '',
        ], $this->rows);
    }

    public function headings(): array
    {
        return ['Variant SKU', 'Variant Price', 'Variant Discount', 'Sell Ahead'];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,      // keep SKUs exactly as typed
            'B' => NumberFormat::FORMAT_NUMBER_00,
            'C' => NumberFormat::FORMAT_NUMBER_00,
        ];
    }
}
