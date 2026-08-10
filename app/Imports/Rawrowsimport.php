<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

/**
 * Generic "give me the raw rows" importer, shared by every bulk-import
 * endpoint. Row 1 is the header (created by each module's "Download
 * Template" button), so we start reading at row 2 and hand back plain
 * rows — the controller decides what each column means per module.
 */
class RawRowsImport implements ToCollection, WithStartRow
{
    protected Collection $rows;

    public function collection(Collection $rows)
    {
        $this->rows = $rows;
    }

    public function startRow(): int
    {
        return 2;
    }

    public function getRows(): Collection
    {
        return $this->rows ?? collect();
    }
}