<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Generic "give me the raw rows" importer shared by every bulk item import.
 * Row 1 is the header row; it is kept separately so the controller can map
 * columns by their header text (and fall back to column order).
 */
class RawRowsImport implements ToCollection
{
    protected Collection $rows;
    protected array $header = [];

    public function collection(Collection $rows)
    {
        $this->header = $rows->isNotEmpty() ? array_map(fn ($h) => trim((string) $h), $rows->first()->toArray()) : [];
        $this->rows   = $rows->slice(1)->values();
    }

    public function getHeader(): array
    {
        return $this->header;
    }

    public function getRows(): Collection
    {
        return $this->rows ?? collect();
    }
}
