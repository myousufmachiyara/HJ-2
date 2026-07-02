<?php

namespace App\Jobs;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductImport;
use App\Models\ProductVariation;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportProductChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1; // rows already retry-safe via updateOrCreate; avoid double-processing

    /**
     * @param  array<int,array<string,mixed>>  $rows  Pre-parsed rows from the prepare job.
     */
    public function __construct(public int $importId, public array $rows) {}

    public function handle(): void
    {
        @set_time_limit(0);

        if ($this->batch()?->cancelled()) {
            return;
        }

        $dbAttributes = Attribute::orderBy('id')->get()->keyBy(fn ($a) => strtolower($a->name));

        $pCreated = $pUpdated = $pFailed = 0;
        $vCreated = $vUpdated = $vFailed = 0;

        foreach ($this->rows as $i => $rowData) {
            $productSku   = trim($rowData['product sku']   ?? '');
            $variationSku = trim($rowData['variation sku'] ?? '');
            if ($productSku === '') continue;

            try {
                $productName = trim($rowData['product name'] ?? '');
                if (strtolower($productName) === 'nan') $productName = '';

                if ($productName !== '') {
                    $conflict = Product::where('name', $productName)
                        ->where('sku', '!=', $productSku)->exists();
                    if ($conflict) $productName .= ' [' . $productSku . ']';
                }

                $rawSkuOpeningDate = trim($rowData['sku opening date'] ?? '');
                $skuOpeningDate    = null;
                if ($rawSkuOpeningDate !== '' && strtolower($rawSkuOpeningDate) !== 'nan') {
                    try {
                        $skuOpeningDate = \Carbon\Carbon::parse($rawSkuOpeningDate)->format('Y-m-d');
                    } catch (Throwable) {
                        $skuOpeningDate = null;
                    }
                }

                $rawProductBarcode = trim($rowData['product barcode'] ?? '');

                $wasNew  = ! Product::where('sku', $productSku)->exists();
                $product = Product::updateOrCreate(
                    ['sku' => $productSku],
                    [
                        'name'              => $productName,
                        'brand'             => trim($rowData['brand'] ?? '') ?: null,
                        'barcode'           => $rawProductBarcode !== '' && strtolower($rawProductBarcode) !== 'nan' ? $rawProductBarcode : null,
                        'sku_opening_date'  => $skuOpeningDate,
                        'category_id'       => $rowData['_category_id'],
                        'subcategory_id'    => $rowData['_subcategory_id'],
                        'measurement_unit'  => $rowData['_unit_id'],
                        'item_type'         => trim($rowData['item type'] ?? 'fg') ?: 'fg',
                        'description'       => trim($rowData['description'] ?? '') ?: null,
                        'vendor_id'         => is_numeric($rowData['vendor id'] ?? '') && (int) ($rowData['vendor id']) > 0
                                               ? (int) $rowData['vendor id'] : null,
                        'weight'            => is_numeric($rowData['weight']          ?? null) ? (float) $rowData['weight']          : null,
                        'cmt_cost'          => is_numeric($rowData['cmt cost']         ?? null) ? (float) $rowData['cmt cost']         : 0,
                        'cost_price'        => is_numeric($rowData['cost price']       ?? null) ? (float) $rowData['cost price']       : 0,
                        'selling_price'     => is_numeric($rowData['selling price']    ?? null) ? (float) $rowData['selling price']    : 0,
                        'compare_at_price'  => is_numeric($rowData['compare at price'] ?? null) ? (float) $rowData['compare at price'] : null,
                        'opening_stock'     => is_numeric($rowData['opening stock']    ?? null) ? (float) $rowData['opening stock']    : 0,
                        'reorder_level'     => is_numeric($rowData['reorder level']    ?? null) ? (float) $rowData['reorder level']    : 0,
                        'max_stock_level'   => is_numeric($rowData['max stock level']  ?? null) ? (float) $rowData['max stock level']  : 0,
                        'minimum_order_qty' => is_numeric($rowData['min order qty']    ?? null) ? (float) $rowData['min order qty']    : 1,
                    ]
                );
                $wasNew ? $pCreated++ : $pUpdated++;

            } catch (Throwable $e) {
                $pFailed++;
                Log::error('[Bulk Import] Product failed', ['sku' => $productSku, 'error' => $e->getMessage()]);
                continue;
            }

            if ($variationSku === '') continue;

            try {
                $wasNew    = ! ProductVariation::where('sku', $variationSku)->exists();
                $variation = ProductVariation::updateOrCreate(
                    ['sku' => $variationSku],
                    [
                        'product_id'     => $product->id,
                        'barcode'        => trim($rowData['variation barcode'] ?? '') ?: null,
                        'stock_quantity' => is_numeric($rowData['variation stock'] ?? null) ? (float) $rowData['variation stock'] : 0,
                    ]
                );

                $syncIds = [];
                foreach ($dbAttributes as $attrKey => $attribute) {
                    $value = trim($rowData[$attrKey] ?? '');
                    if ($value === '' || strtolower($value) === 'nan') continue;

                    $attrValue = AttributeValue::firstOrCreate(
                        ['attribute_id' => $attribute->id, 'value' => ucfirst(strtolower($value))]
                    );
                    $syncIds[] = $attrValue->id;
                }
                $variation->attributeValues()->sync($syncIds);

                $wasNew ? $vCreated++ : $vUpdated++;

            } catch (Throwable $e) {
                $vFailed++;
                Log::error('[Bulk Import] Variation failed', ['variation_sku' => $variationSku, 'error' => $e->getMessage()]);
            }
        }

        // One write per chunk — atomic increments are safe across workers.
        ProductImport::whereKey($this->importId)->update([
            'products_created'   => \DB::raw("products_created + {$pCreated}"),
            'products_updated'   => \DB::raw("products_updated + {$pUpdated}"),
            'products_failed'    => \DB::raw("products_failed + {$pFailed}"),
            'variations_created' => \DB::raw("variations_created + {$vCreated}"),
            'variations_updated' => \DB::raw("variations_updated + {$vUpdated}"),
            'variations_failed'  => \DB::raw("variations_failed + {$vFailed}"),
        ]);
    }
}