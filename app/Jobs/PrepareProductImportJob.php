<?php

namespace App\Jobs;

use App\Models\Attribute;
use App\Models\MeasurementUnit;
use App\Models\ProductCategory;
use App\Models\ProductImport;
use App\Models\ProductSubcategory;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class PrepareProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;   // reading + parsing a big file can take a while
    public int $tries   = 1;

    private const CHUNK_SIZE = 100;

    public function __construct(public int $importId) {}

    public function handle(): void
    {
        @set_time_limit(0);

        $import = ProductImport::find($this->importId);
        if (! $import) return;

        $import->update(['status' => 'preparing']);

        try {
            // ── Read file from storage ───────────────────────────────────
            $rows = Excel::toArray([], $import->file_path, 'local')[0] ?? [];
            if (empty($rows)) {
                throw new \Exception('Uploaded file is empty.');
            }

            $rawHeader = array_shift($rows);
            $header    = array_map(fn ($h) => strtolower(trim((string) $h)), $rawHeader);
            $colCount  = count($header);

            // drop helper/instruction row if present
            if (! empty($rows) && str_starts_with(trim((string) ($rows[0][0] ?? '')), '←')) {
                array_shift($rows);
            }

            $categoryMap    = ProductCategory::all()->keyBy(fn ($c) => strtolower(trim($c->name)));
            $subcategoryMap = ProductSubcategory::all()->keyBy(fn ($s) => strtolower(trim($s->name)));
            $defaultUnit    = MeasurementUnit::first()?->id ?? 1;

            $resolveCategory = function (string $raw) use (&$categoryMap): ?int {
                $raw = trim($raw);
                if ($raw === '' || strtolower($raw) === 'nan') return null;
                if (str_starts_with(strtolower($raw), 'http')) return null;
                if (is_numeric($raw)) return (int) $raw > 0 ? (int) $raw : null;

                $key = strtolower($raw);
                if (! isset($categoryMap[$key])) {
                    $categoryMap[$key] = ProductCategory::create([
                        'name' => ucwords($raw),
                        'code' => Str::slug($raw),
                    ]);
                }
                return $categoryMap[$key]->id;
            };

            $fallbackCategoryId = function () use (&$categoryMap): int {
                if ($categoryMap->isNotEmpty()) return $categoryMap->first()->id;
                $cat = ProductCategory::create(['name' => 'Imported', 'code' => 'imported']);
                $categoryMap['imported'] = $cat;
                return $cat->id;
            };

            $resolveSubcategory = function (string $raw) use (&$subcategoryMap): ?int {
                $raw = trim($raw);
                if ($raw === '' || strtolower($raw) === 'nan') return null;
                if (is_numeric($raw)) return (int) $raw > 0 ? (int) $raw : null;

                $key = strtolower($raw);
                if (! isset($subcategoryMap[$key])) {
                    $subcategoryMap[$key] = ProductSubcategory::create(['name' => ucwords($raw)]);
                }
                return $subcategoryMap[$key]->id;
            };

            // ── First pass (your existing logic, intact) ─────────────────
            $parsedRows  = [];
            $lastProduct = [];
            $seenVarSKUs = [];

            foreach ($rows as $row) {
                $rowValues = array_filter(array_map('trim', array_map('strval', $row)));
                if (empty($rowValues)) continue;

                $row    = array_map('strval', $row);
                $rowPad = array_pad(array_slice($row, 0, $colCount), $colCount, '');
                $data   = array_combine($header, $rowPad);

                $productSku = trim($data['product sku'] ?? '');
                if (str_starts_with($productSku, '←') || $productSku === '') continue;

                $productName = trim($data['product name'] ?? '');
                $isNan       = strtolower($productName) === 'nan';

                if ($productName === '' || $isNan) {
                    if (isset($lastProduct[$productSku])) {
                        foreach ([
                            'product name', 'product barcode', 'brand', 'category id', 'subcategory id', 'unit id',
                            'item type', 'description', 'vendor id', 'weight', 'sku opening date',
                            'cmt cost', 'cost price', 'selling price', 'compare at price',
                            'opening stock', 'reorder level', 'max stock level', 'min order qty',
                        ] as $col) {
                            if (($data[$col] ?? '') === '' || strtolower($data[$col] ?? '') === 'nan') {
                                $data[$col] = $lastProduct[$productSku][$col] ?? '';
                            }
                        }
                    }
                } else {
                    $lastProduct[$productSku] = $data;
                }

                $variationSku = trim($data['variation sku'] ?? '');
                if ($variationSku !== '') {
                    if (isset($seenVarSKUs[$variationSku])) {
                        $engravingKey = strtolower('add engraving?');
                        $prevData     = &$seenVarSKUs[$variationSku]['data'];
                        $prevEng      = strtoupper(trim($prevData[$engravingKey] ?? ''));
                        $currEng      = strtoupper(trim($data[$engravingKey] ?? ''));

                        if ($prevEng !== '' && $currEng !== '') {
                            $prevData['variation sku'] = $variationSku . '-' . $prevEng;
                            $data['variation sku']     = $variationSku . '-' . $currEng;
                        }
                    } else {
                        $seenVarSKUs[$variationSku] = ['data' => &$data];
                    }
                }

                // Resolve FK ids ONCE here so chunk workers never create dup categories.
                $catId = $resolveCategory($data['category id'] ?? '');
                if ($catId === null) $catId = $fallbackCategoryId();
                $rawUnitId = trim($data['unit id'] ?? '');

                $data['_category_id']    = $catId;
                $data['_subcategory_id'] = $resolveSubcategory($data['subcategory id'] ?? '');
                $data['_unit_id']        = is_numeric($rawUnitId) && (int) $rawUnitId > 0 ? (int) $rawUnitId : $defaultUnit;

                $parsedRows[] = $data;
                unset($data); // break the reference before next iteration
            }

            if (empty($parsedRows)) {
                $import->update(['status' => 'completed', 'message' => 'No valid rows found in file.']);
                $this->cleanup($import);
                return;
            }

            // Pre-compute the full SKU sets for delete_missing (race-free).
            $productSkus = collect($parsedRows)
                ->pluck('product sku')->map(fn ($s) => trim((string) $s))
                ->filter()->unique()->values()->all();

            $variationSkus = collect($parsedRows)
                ->pluck('variation sku')->map(fn ($s) => trim((string) $s))
                ->filter()->unique()->values()->all();

            Cache::put("import:{$import->id}:product_skus", $productSkus, now()->addHours(6));
            Cache::put("import:{$import->id}:variation_skus", $variationSkus, now()->addHours(6));

            $import->update(['total_rows' => count($parsedRows), 'status' => 'processing']);

            // ── Build the batch ──────────────────────────────────────────
            $importId      = $import->id;
            $deleteMissing = $import->delete_missing;

            $jobs = collect($parsedRows)
                ->chunk(self::CHUNK_SIZE)
                ->map(fn ($chunk) => new ImportProductChunkJob($importId, $chunk->values()->all()))
                ->all();

            $batch = Bus::batch($jobs)
                ->name("product-import:{$importId}")
                ->onConnection('database')
                ->onQueue('imports')
                ->allowFailures() // one bad chunk shouldn't cancel the rest
                ->then(function () use ($importId, $deleteMissing) {
                    // Runs only if every chunk finished without throwing.
                    if ($deleteMissing) {
                        $pSkus = Cache::get("import:{$importId}:product_skus", []);
                        $vSkus = Cache::get("import:{$importId}:variation_skus", []);
                        if (! empty($vSkus)) {
                            \App\Models\ProductVariation::whereNotIn('sku', $vSkus)->delete();
                        }
                        if (! empty($pSkus)) {
                            \App\Models\Product::whereNotIn('sku', $pSkus)->delete();
                        }
                    }
                })
                ->finally(function () use ($importId) {
                    $imp = ProductImport::find($importId);
                    if (! $imp) return;

                    $hadFailures = $imp->products_failed > 0 || $imp->variations_failed > 0;
                    $imp->update([
                        'status'  => $hadFailures ? 'completed' : 'completed',
                        'message' => sprintf(
                            'Products: %d created, %d updated%s | Variations: %d created, %d updated%s',
                            $imp->products_created, $imp->products_updated,
                            $imp->products_failed ? ", {$imp->products_failed} failed" : '',
                            $imp->variations_created, $imp->variations_updated,
                            $imp->variations_failed ? ", {$imp->variations_failed} failed" : ''
                        ),
                    ]);

                    Cache::forget("import:{$importId}:product_skus");
                    Cache::forget("import:{$importId}:variation_skus");
                    if ($imp->file_path && Storage::disk('local')->exists($imp->file_path)) {
                        Storage::disk('local')->delete($imp->file_path);
                    }
                })
                ->dispatch();

            $import->update(['batch_id' => $batch->id]);

        } catch (Throwable $e) {
            Log::error('[Bulk Import Prepare] Fatal', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $import->update(['status' => 'failed', 'message' => 'Import failed: ' . $e->getMessage()]);
            $this->cleanup($import);
        }
    }

    private function cleanup(ProductImport $import): void
    {
        if ($import->file_path && Storage::disk('local')->exists($import->file_path)) {
            Storage::disk('local')->delete($import->file_path);
        }
    }

    public function failed(Throwable $e): void
    {
        ProductImport::where('id', $this->importId)
            ->update(['status' => 'failed', 'message' => 'Prepare job crashed: ' . $e->getMessage()]);
    }
}