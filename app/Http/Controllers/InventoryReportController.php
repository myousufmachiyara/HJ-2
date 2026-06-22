<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\Product;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseReturnItem;
use App\Models\SaleInvoiceItem;
use App\Models\SaleReturnItem;
use App\Models\ProductionDetail;
use App\Models\ProductionReceivingDetail;
use App\Models\ProductionReturnItem;
use App\Models\ProductionWastageReceivingDetail;
use App\Models\StockTransferDetail;
use App\Models\Location;

class InventoryReportController extends Controller
{
    public function inventoryReports(Request $request)
    {
        $tab        = $request->tab ?? 'IL';
        $selected   = $request->item_id ?? null;
        $from       = $request->from_date ?? now()->startOfMonth()->toDateString();
        $to         = $request->to_date   ?? now()->toDateString();
        $locationId = $request->location_id ?? null;
        $locations  = Location::all();

        $productId   = null;
        $variationId = null;
        if ($selected) {
            $parts = explode('-', $selected, 2);
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $productId   = (int) $parts[0];
                $variationId = (int) $parts[1];
            } else {
                $productId = (int) $selected;
            }
        }

        $allProducts = Product::with('variations')->get();

        $itemLedger     = collect();
        $stockInHand    = collect();
        $wastageStock   = collect();
        $stockTransfers = collect();
        $nonMovingItems = collect();
        $reorderLevel   = collect();

        // ── Helper: purchase cost by costing method ───────────────────
        $getPurchaseCost = function (int $itemId, string $method, ?int $varId = null) {
            $hasVarSpecific = $varId
                ? PurchaseInvoiceItem::where('item_id', $itemId)->where('variation_id', $varId)->exists()
                : false;

            if ($varId && $hasVarSpecific) {
                $pq = PurchaseInvoiceItem::where('item_id', $itemId)->where('variation_id', $varId);
            } elseif ($varId && !$hasVarSpecific) {
                $pq = PurchaseInvoiceItem::where('item_id', $itemId)->whereNull('variation_id');
                if (!$pq->exists()) return 0;
            } else {
                $pq = PurchaseInvoiceItem::where('item_id', $itemId);
            }

            return match ($method) {
                'max'    => (float) ($pq->max('price') ?? 0),
                'min'    => (float) ($pq->min('price') ?? 0),
                'latest' => (float) (optional($pq->latest('id')->first())->price ?? 0),
                default  => (function () use ($pq) {
                    $agg = $pq->selectRaw('SUM(quantity * price) as v, SUM(quantity) as q')->first();
                    return ($agg && $agg->q > 0) ? ($agg->v / $agg->q) : 0;
                })(),
            };
        };

        // ── Helper: current real stock qty (optionally as-of a date, exclusive) ──
        // Only 'extra' type wastage returns come back to real stock.
        // 'wastage' type is a write-off — not counted in inventory.
        $getStockQty = function (Product $product, ?object $var, ?string $asOfDate = null) {
            $vid = $var->id ?? null;

            $openingStock   = $vid
                ? (float) ($var->stock_quantity ?? 0)
                : (float) ($product->opening_stock ?? 0);

            $purchased      = (float) PurchaseInvoiceItem::where('item_id', $product->id)
                                ->when($vid, fn($q) => $q->where('variation_id', $vid))
                                ->when($asOfDate, fn($q) => $q->whereHas('invoice', fn($q2) => $q2->where('invoice_date', '<', $asOfDate)))
                                ->sum('quantity');

            $purchaseReturn = (float) PurchaseReturnItem::where('item_id', $product->id)
                                ->when($vid, fn($q) => $q->where('variation_id', $vid))
                                ->when($asOfDate, fn($q) => $q->whereHas('purchaseReturn', fn($q2) => $q2->where('return_date', '<', $asOfDate)))
                                ->sum('quantity');

            $sold           = (float) SaleInvoiceItem::where('product_id', $product->id)
                                ->when($vid, fn($q) => $q->where('variation_id', $vid))
                                ->when($asOfDate, fn($q) => $q->whereHas('invoice', fn($q2) => $q2->where('date', '<', $asOfDate)))
                                ->sum('quantity');

            $saleReturn     = (float) SaleReturnItem::where('product_id', $product->id)
                                ->when($vid, fn($q) => $q->where('variation_id', $vid))
                                ->when($asOfDate, fn($q) => $q->whereHas('saleReturn', fn($q2) => $q2->where('return_date', '<', $asOfDate)))
                                ->sum('qty');

            $rawIssued      = (float) ProductionDetail::where('product_id', $product->id)
                                ->when($vid, fn($q) => $q->where('variation_id', $vid))
                                ->when($asOfDate, fn($q) => $q->whereHas('production', fn($q2) => $q2->where('order_date', '<', $asOfDate)))
                                ->sum('qty');

            $fgReceived     = (float) ProductionReceivingDetail::where('product_id', $product->id)
                                ->when($vid, fn($q) => $q->where('variation_id', $vid))
                                ->when($asOfDate, fn($q) => $q->whereHas('receiving', fn($q2) => $q2->where('rec_date', '<', $asOfDate)))
                                ->sum('received_qty');

            $fgReturned     = (float) ProductionReturnItem::where('product_id', $product->id)
                                ->when($vid, fn($q) => $q->where('variation_id', $vid))
                                ->when($asOfDate, fn($q) => $q->whereHas('productionReturn', fn($q2) => $q2->where('return_date', '<', $asOfDate)))
                                ->sum('quantity');

            // ONLY extra type → back to real stock (wastage type = write-off, excluded)
            $wastageIn      = (float) ProductionWastageReceivingDetail::where('product_id', $product->id)
                                ->where('return_type', 'extra')
                                ->when($vid, fn($q) => $q->where('variation_id', $vid))
                                ->when($asOfDate, fn($q) => $q->whereHas('wastageReceiving', fn($q2) => $q2->where('rec_date', '<', $asOfDate)))
                                ->sum('quantity');

            return $openingStock
                + $purchased
                - $purchaseReturn
                + $saleReturn
                + $fgReceived
                + $wastageIn
                - $sold
                - $rawIssued
                - $fgReturned;
        };

        // ────────────────────────────────────────────────────────────────
        // 1. ITEM LEDGER
        // ────────────────────────────────────────────────────────────────
        if ($tab === 'IL' && $productId) {
            $product = $allProducts->firstWhere('id', $productId);

            if ($product) {
                if ($variationId) {
                    $var        = $product->variations->firstWhere('id', $variationId);
                    $variations = $var
                        ? collect([$var])
                        : collect([(object)['id' => $variationId, 'sku' => null]]);
                } else {
                    $variations = $product->variations->isNotEmpty()
                        ? $product->variations
                        : collect([(object)['id' => null, 'sku' => null]]);
                }

                foreach ($variations as $var) {
                    $vid    = $var->id ?? null;
                    $ledger = collect();

                    // ── Opening Balance row: net stock from all movements strictly before $from ──
                    $openingBalanceQty = $getStockQty($product, $var, $from);

                    $openingRow = null;
                    if ($openingBalanceQty != 0) {
                        $openingRow = [
                            'date'        => $from,
                            'type'        => 'Opening Balance',
                            'description' => 'Stock brought forward (as of '
                                . \Carbon\Carbon::parse($from)->subDay()->format('d-M-Y') . ')',
                            'qty_in'      => $openingBalanceQty > 0 ? $openingBalanceQty : 0,
                            'qty_out'     => $openingBalanceQty < 0 ? abs($openingBalanceQty) : 0,
                            'rate'        => 0,
                            'product'     => $product->name,
                            'variation'   => $var->sku ?? null,
                            'is_writeoff' => false,
                            'writeoff_qty'=> 0,
                        ];
                    }

                    // Purchases IN
                    $ledger = $ledger->concat(
                        PurchaseInvoiceItem::with('invoice')
                            ->where('item_id', $product->id)
                            ->when($vid, fn($q) => $q->where('variation_id', $vid))
                            ->whereHas('invoice', fn($q) => $q->whereBetween('invoice_date', [$from, $to]))
                            ->get()
                            ->map(fn($row) => [
                                'date'        => $row->invoice->invoice_date,
                                'type'        => 'Purchase',
                                'description' => 'Invoice: ' . ($row->invoice->invoice_no ?? $row->invoice->id)
                                    . ($row->invoice->bill_no ? ' | Bill: ' . $row->invoice->bill_no : ''),
                                'qty_in'      => $row->quantity,
                                'qty_out'     => 0,
                                'rate'        => $row->price,
                                'product'     => $product->name,
                                'variation'   => $var->sku ?? null,
                                'is_writeoff' => false,
                                'writeoff_qty'=> 0,
                            ])
                    );

                    // Purchase Returns OUT
                    $ledger = $ledger->concat(
                        PurchaseReturnItem::with('purchaseReturn')
                            ->where('item_id', $product->id)
                            ->when($vid, fn($q) => $q->where('variation_id', $vid))
                            ->whereHas('purchaseReturn', fn($q) => $q->whereBetween('return_date', [$from, $to]))
                            ->get()
                            ->map(fn($row) => [
                                'date'        => $row->purchaseReturn->return_date,
                                'type'        => 'Purchase Return',
                                'description' => 'Return #' . ($row->purchaseReturn->reference_no ?? $row->purchaseReturn->id),
                                'qty_in'      => 0,
                                'qty_out'     => $row->quantity,
                                'rate'        => $row->price ?? 0,
                                'product'     => $product->name,
                                'variation'   => $var->sku ?? null,
                                'is_writeoff' => false,
                                'writeoff_qty'=> 0,
                            ])
                    );

                    // Sales OUT
                    $ledger = $ledger->concat(
                        SaleInvoiceItem::with('invoice')
                            ->where('product_id', $product->id)
                            ->when($vid, fn($q) => $q->where('variation_id', $vid))
                            ->whereHas('invoice', fn($q) => $q->whereBetween('date', [$from, $to]))
                            ->get()
                            ->map(fn($row) => [
                                'date'        => $row->invoice->date,
                                'type'        => 'Sale',
                                'description' => 'Invoice: ' . ($row->invoice->invoice_no ?? $row->invoice->id),
                                'qty_in'      => 0,
                                'qty_out'     => $row->quantity,
                                'rate'        => $row->sale_price ?? 0,
                                'product'     => $product->name,
                                'variation'   => $var->sku ?? null,
                                'is_writeoff' => false,
                                'writeoff_qty'=> 0,
                            ])
                    );

                    // Sale Returns IN
                    $ledger = $ledger->concat(
                        SaleReturnItem::with('saleReturn')
                            ->where('product_id', $product->id)
                            ->when($vid, fn($q) => $q->where('variation_id', $vid))
                            ->whereHas('saleReturn', fn($q) => $q->whereBetween('return_date', [$from, $to]))
                            ->get()
                            ->map(fn($row) => [
                                'date'        => $row->saleReturn->return_date,
                                'type'        => 'Sale Return',
                                'description' => 'Return #' . ($row->saleReturn->reference_no ?? $row->saleReturn->id),
                                'qty_in'      => $row->qty,
                                'qty_out'     => 0,
                                'rate'        => 0,
                                'product'     => $product->name,
                                'variation'   => $var->sku ?? null,
                                'is_writeoff' => false,
                                'writeoff_qty'=> 0,
                            ])
                    );

                    // Production Order — raw OUT
                    $ledger = $ledger->concat(
                        ProductionDetail::with('production')
                            ->where('product_id', $product->id)
                            ->when($vid, fn($q) => $q->where('variation_id', $vid))
                            ->whereHas('production', fn($q) => $q->whereBetween('order_date', [$from, $to]))
                            ->get()
                            ->map(fn($row) => [
                                'date'        => $row->production->order_date,
                                'type'        => 'Production Order',
                                'description' => 'PO-' . str_pad($row->production->id, 4, '0', STR_PAD_LEFT)
                                    . ' — Raw issued to vendor',
                                'qty_in'      => 0,
                                'qty_out'     => $row->qty,
                                'rate'        => $row->rate ?? 0,
                                'product'     => $product->name,
                                'variation'   => $var->sku ?? null,
                                'is_writeoff' => false,
                                'writeoff_qty'=> 0,
                            ])
                    );

                    // Production Receiving — FG IN
                    $ledger = $ledger->concat(
                        ProductionReceivingDetail::with('receiving')
                            ->where('product_id', $product->id)
                            ->when($vid, fn($q) => $q->where('variation_id', $vid))
                            ->whereHas('receiving', fn($q) => $q->whereBetween('rec_date', [$from, $to]))
                            ->get()
                            ->map(fn($row) => [
                                'date'        => $row->receiving->rec_date,
                                'type'        => 'Production Receiving',
                                'description' => 'GRN: ' . ($row->receiving->grn_no ?? $row->receiving->id)
                                    . ($row->receiving->production_id
                                        ? ' — PO-' . $row->receiving->production_id : '')
                                    . ' | Mfg Cost: ' . number_format($row->manufacturing_cost ?? 0, 2),
                                'qty_in'      => $row->received_qty,
                                'qty_out'     => 0,
                                'rate'        => $row->manufacturing_cost ?? 0,
                                'product'     => $product->name,
                                'variation'   => $var->sku ?? null,
                                'is_writeoff' => false,
                                'writeoff_qty'=> 0,
                            ])
                    );

                    // Production Return — FG OUT
                    $ledger = $ledger->concat(
                        ProductionReturnItem::with('productionReturn')
                            ->where('product_id', $product->id)
                            ->when($vid, fn($q) => $q->where('variation_id', $vid))
                            ->whereHas('productionReturn', fn($q) => $q->whereBetween('return_date', [$from, $to]))
                            ->get()
                            ->map(fn($row) => [
                                'date'        => $row->productionReturn->return_date,
                                'type'        => 'Production Return',
                                'description' => 'Return #' . $row->productionReturn->id
                                    . ($row->production_id ? ' — PO-' . $row->production_id : '')
                                    . ' — Defective FG returned to vendor',
                                'qty_in'      => 0,
                                'qty_out'     => $row->quantity,
                                'rate'        => $row->price ?? 0,
                                'product'     => $product->name,
                                'variation'   => $var->sku ?? null,
                                'is_writeoff' => false,
                                'writeoff_qty'=> 0,
                            ])
                    );

                    // Wastage Receiving — split by return_type
                    $ledger = $ledger->concat(
                        ProductionWastageReceivingDetail::with('wastageReceiving')
                            ->where('product_id', $product->id)
                            ->when($vid, fn($q) => $q->where('variation_id', $vid))
                            ->whereHas('wastageReceiving', fn($q) => $q->whereBetween('rec_date', [$from, $to]))
                            ->get()
                            ->map(function ($row) use ($product, $var) {
                                $isExtra = ($row->return_type ?? 'extra') === 'extra';
                                return [
                                    'date'        => $row->wastageReceiving->rec_date,
                                    'type'        => $isExtra
                                        ? 'Wastage Return (Extra)'
                                        : 'Wastage Return (W/O)',
                                    'description' => 'WRN: ' . ($row->wastageReceiving->grn_no ?? $row->wastageReceiving->id)
                                        . ($row->wastageReceiving->production_id
                                            ? ' — PO-' . $row->wastageReceiving->production_id : '')
                                        . ($isExtra
                                            ? ' — Unused raw returned to stock'
                                            : ' — Wastage written off (no stock movement)'),
                                    'qty_in'      => $isExtra ? $row->quantity : 0,
                                    'qty_out'     => 0,
                                    'rate'        => 0,
                                    'product'     => $product->name,
                                    'variation'   => $var->sku ?? null,
                                    'is_writeoff' => !$isExtra,
                                    'writeoff_qty'=> !$isExtra ? $row->quantity : 0,
                                ];
                            })
                    );

                    // Sort period movements by date, then prepend opening balance (always first)
                    $periodMovements = $ledger->sortBy('date')->values();

                    if ($openingRow) {
                        $itemLedger->push($openingRow);
                    }
                    $itemLedger = $itemLedger->concat($periodMovements);
                }
            }
        }

        // ────────────────────────────────────────────────────────────────
        // 2. STOCK IN HAND
        // ────────────────────────────────────────────────────────────────
        if ($tab === 'SR') {
            $costingMethod     = $request->costing_method ?? 'avg';
            $productsToProcess = $allProducts;

            if ($selected) {
                if ($variationId) {
                    $productsToProcess = $allProducts->where('id', $productId)->map(function ($product) use ($variationId) {
                        $product             = clone $product;
                        $product->variations = $product->variations->where('id', $variationId)->values();
                        return $product;
                    });
                } else {
                    $productsToProcess = $allProducts->where('id', $productId)->values();
                }
            }

            $productIds = $productsToProcess->pluck('id')->toArray();

            // ── FIX: Build a wider product-ID set for purchase-price lookups only. ──
            // $productIds (above) correctly stays scoped to whatever's being displayed
            // in the table, and continues to drive purchasedSums/soldSums/etc below.
            // But manufactured FG items need the purchase price of the RAW MATERIALS
            // consumed in their production batches (see $getRawRate usage further down).
            // When filtering to a single FG product/variation, $productIds only contains
            // that FG's own ID — never the raw material's ID — so $purchasePriceAgg was
            // coming back empty for the raw material and raw_cost silently fell back to 0,
            // even though the same FG showed the correct raw_cost under "All Products"
            // (where every raw material's ID happened to already be present in $productIds).
            // We expand only the purchase-price lookup set below to include those raw
            // material IDs; nothing else in this block changes.
            $fgProductIdsForRawLookup = $productsToProcess
                ->filter(fn($p) => $p->item_type !== 'raw')
                ->pluck('id');

            $rawMaterialIdsNeeded = ProductionReceivingDetail::whereIn('product_id', $fgProductIdsForRawLookup)
                ->with('receiving.production.details')
                ->get()
                ->flatMap(fn($rd) => $rd->receiving->production->details ?? collect())
                ->pluck('product_id')
                ->unique()
                ->values()
                ->toArray();

            $purchaseLookupProductIds = array_values(array_unique(array_merge($productIds, $rawMaterialIdsNeeded)));

            // ── Bulk preload all stock-movement sums, grouped by product_id + variation_id ──
            $purchasedSums = PurchaseInvoiceItem::whereIn('item_id', $productIds)
                ->selectRaw('item_id as product_id, variation_id, SUM(quantity) as total')
                ->groupBy('item_id', 'variation_id')->get()->groupBy('product_id');

            $purchaseReturnSums = PurchaseReturnItem::whereIn('item_id', $productIds)
                ->selectRaw('item_id as product_id, variation_id, SUM(quantity) as total')
                ->groupBy('item_id', 'variation_id')->get()->groupBy('product_id');

            $soldSums = SaleInvoiceItem::whereIn('product_id', $productIds)
                ->selectRaw('product_id, variation_id, SUM(quantity) as total')
                ->groupBy('product_id', 'variation_id')->get()->groupBy('product_id');

            $saleReturnSums = SaleReturnItem::whereIn('product_id', $productIds)
                ->selectRaw('product_id, variation_id, SUM(qty) as total')
                ->groupBy('product_id', 'variation_id')->get()->groupBy('product_id');

            $rawIssuedSums = ProductionDetail::whereIn('product_id', $productIds)
                ->selectRaw('product_id, variation_id, SUM(qty) as total')
                ->groupBy('product_id', 'variation_id')->get()->groupBy('product_id');

            $fgReceivedSums = ProductionReceivingDetail::whereIn('product_id', $productIds)
                ->selectRaw('product_id, variation_id, SUM(received_qty) as total')
                ->groupBy('product_id', 'variation_id')->get()->groupBy('product_id');

            $fgReturnedSums = ProductionReturnItem::whereIn('product_id', $productIds)
                ->selectRaw('product_id, variation_id, SUM(quantity) as total')
                ->groupBy('product_id', 'variation_id')->get()->groupBy('product_id');

            $wastageExtraSums = ProductionWastageReceivingDetail::whereIn('product_id', $productIds)
                ->where('return_type', 'extra')
                ->selectRaw('product_id, variation_id, SUM(quantity) as total')
                ->groupBy('product_id', 'variation_id')->get()->groupBy('product_id');

            // ── Bulk preload purchase price aggregates (for raw cost), per product+variation AND per product (null variation) ──
            // FIX: uses $purchaseLookupProductIds (productIds + raw material IDs) instead of $productIds,
            // so raw materials consumed by a filtered FG product are included.
            $purchasePriceAgg = PurchaseInvoiceItem::whereIn('item_id', $purchaseLookupProductIds)
                ->selectRaw('item_id as product_id, variation_id,
                    SUM(quantity * price) as sum_value,
                    SUM(quantity) as sum_qty,
                    MAX(price) as max_price,
                    MIN(price) as min_price')
                ->groupBy('item_id', 'variation_id')
                ->get()
                ->groupBy('product_id');

            // Latest price per product+variation (and per product null-variation)
            // FIX: uses $purchaseLookupProductIds for the same reason as above.
            $latestPriceRows = PurchaseInvoiceItem::whereIn('item_id', $purchaseLookupProductIds)
                ->orderBy('id', 'desc')
                ->get(['item_id', 'variation_id', 'price', 'id'])
                ->groupBy('item_id');

            $latestPriceFor = function ($productId, $vid) use ($latestPriceRows) {
                $rows = $latestPriceRows->get($productId, collect());
                $match = $rows->firstWhere('variation_id', $vid);
                if (!$match && $vid !== null) {
                    $match = $rows->firstWhere('variation_id', null);
                }
                return $match ? (float) $match->price : 0;
            };

            $purchaseAggFor = function ($productId, $vid, $method) use ($purchasePriceAgg, $latestPriceFor) {
                $rows = $purchasePriceAgg->get($productId, collect());

                $row = $rows->firstWhere('variation_id', $vid);
                $hasVarSpecific = $vid !== null && $row !== null;

                if ($vid !== null && !$hasVarSpecific) {
                    // fall back to null-variation row for this product
                    $row = $rows->firstWhere('variation_id', null);
                    if (!$row) return null; // no purchase data at all
                }

                if (!$row) return 0;

                return match ($method) {
                    'max'    => (float) $row->max_price,
                    'min'    => (float) $row->min_price,
                    'latest' => $latestPriceFor($productId, $hasVarSpecific ? $vid : null),
                    default  => $row->sum_qty > 0 ? ((float) $row->sum_value / (float) $row->sum_qty) : 0,
                };
            };

            $directlyPurchasedSet = $purchasePriceAgg->keys()->flip(); // product_id => true if any purchase exists

            // ── For manufactured FG: preload production receivings with production+details in one go ──
            $fgProductIds = $productsToProcess->filter(fn($p) => $p->item_type !== 'raw')->pluck('id')->toArray();

            $allReceivingsForFg = ProductionReceivingDetail::whereIn('product_id', $fgProductIds)
                ->with([
                    'receiving.production.details',
                    'receiving.production.receivings.details',
                    'receiving.production.wastageReceivings.details',
                ])
                ->get()
                ->groupBy(['product_id', 'variation_id']);

            // Cache raw rate per raw-product (avoids re-querying same raw material repeatedly)
            $rawRateCache = [];
            $getRawRate = function ($rawProductId, $method) use (&$rawRateCache, $purchaseAggFor) {
                $key = $rawProductId . '-' . $method;
                if (!isset($rawRateCache[$key])) {
                    $agg = $purchaseAggFor($rawProductId, null, $method);
                    $rawRateCache[$key] = $agg ?? 0;
                }
                return $rawRateCache[$key];
            };

            // ── Cache: fraction of issued raw that was actually consumed, per production ──
            // consumedFraction = (rawIssued - extraReturned - wastageWriteoff) / rawIssued
            // This excludes raw that was returned to stock ('extra') or written off ('wastage')
            // from loading onto the FG's per-piece cost.
            $consumedFractionCache = [];
            $rawConsumedFractionFor = function ($production) use (&$consumedFractionCache) {
                if (!$production) return 1.0;

                if (isset($consumedFractionCache[$production->id])) {
                    return $consumedFractionCache[$production->id];
                }

                $totalRawGiven = (float) $production->details->sum('qty');

                if ($totalRawGiven <= 0) {
                    return $consumedFractionCache[$production->id] = 1.0;
                }

                $wastageDetails = $production->wastageReceivings
                    ->flatMap->details;

                // null/legacy return_type defaults to 'extra' (back to stock) — matches
                // convention used elsewhere (Item Ledger, Wastage Stock report, etc.)
                $totalExtraReturned   = (float) $wastageDetails
                    ->filter(fn($wd) => ($wd->return_type ?? 'extra') === 'extra')
                    ->sum('quantity');

                $totalWastageWriteoff = (float) $wastageDetails
                    ->filter(fn($wd) => ($wd->return_type ?? 'extra') !== 'extra')
                    ->sum('quantity');

                $rawConsumedTotal = max(0, $totalRawGiven - $totalExtraReturned - $totalWastageWriteoff);

                return $consumedFractionCache[$production->id] = $rawConsumedTotal / $totalRawGiven;
            };

            // ── Helper: sum from preloaded grouped collection ──
            $getSum = function ($groupedByProduct, $productId, $vid) {
                $rows = $groupedByProduct->get($productId, collect());
                $row  = $rows->first(fn($r) => $r->variation_id == $vid);
                return $row ? (float) $row->total : 0;
            };

            foreach ($productsToProcess as $product) {
                $variations = $product->variations->isNotEmpty()
                    ? $product->variations
                    : collect([(object)['id' => null, 'sku' => null, 'stock_quantity' => 0]]);

                foreach ($variations as $var) {
                    $vid = $var->id ?? null;

                    // ── Stock qty (from preloaded sums) ──
                    $openingStock   = $vid
                        ? (float) ($var->stock_quantity ?? 0)
                        : (float) ($product->opening_stock ?? 0);

                    $purchased      = $getSum($purchasedSums,       $product->id, $vid);
                    $purchaseReturn = $getSum($purchaseReturnSums,  $product->id, $vid);
                    $sold           = $getSum($soldSums,            $product->id, $vid);
                    $saleReturn     = $getSum($saleReturnSums,      $product->id, $vid);
                    $rawIssued      = $getSum($rawIssuedSums,       $product->id, $vid);
                    $fgReceived     = $getSum($fgReceivedSums,      $product->id, $vid);
                    $fgReturned     = $getSum($fgReturnedSums,      $product->id, $vid);
                    $wastageIn      = $getSum($wastageExtraSums,    $product->id, $vid);

                    $stockQty = $openingStock
                        + $purchased
                        - $purchaseReturn
                        + $saleReturn
                        + $fgReceived
                        + $wastageIn
                        - $sold
                        - $rawIssued
                        - $fgReturned;

                    $rawCostPerPiece = 0;
                    $mfgCostPerPiece = 0;

                    $isRaw             = $product->item_type === 'raw';
                    $isFg              = !$isRaw;
                    $directlyPurchased = $directlyPurchasedSet->has($product->id);

                    if ($isRaw || ($isFg && $directlyPurchased)) {
                        $agg = $purchaseAggFor($product->id, $vid, $costingMethod);

                        if ($agg === null) {
                            // No purchase data at all for this var or fallback — zero row
                            $stockInHand->push([
                                'product'   => $product->name,
                                'variation' => $var->sku ?? null,
                                'quantity'  => round($stockQty, 4),
                                'raw_cost'  => 0, 'mfg_cost' => 0,
                                'price'     => 0, 'total'    => 0,
                            ]);
                            continue;
                        }

                        $rawCostPerPiece = $agg;

                    } elseif ($isFg && !$directlyPurchased) {
                        $prodReceivings = $allReceivingsForFg->get($product->id, collect())->get($vid, collect());

                        $totalWeightedRawCost = 0;
                        $totalFgQty           = 0;
                        $totalMfgValue        = 0;
                        $totalMfgQty          = 0;

                        foreach ($prodReceivings as $recDetail) {
                            $production = $recDetail->receiving->production ?? null;

                            $batchFgQty = (float) $recDetail->received_qty;

                            if ($production) {
                                $batchRawCostIssued = 0;
                                foreach ($production->details as $rawDetail) {
                                    $rawRate             = $getRawRate($rawDetail->product_id, $costingMethod);
                                    $batchRawCostIssued += (float) $rawDetail->qty * $rawRate;
                                }

                                // Scale down to only the raw actually consumed — exclude
                                // extra raw returned to stock and wastage written off,
                                // so their cost doesn't inflate this FG's per-piece cost.
                                $consumedFraction = $rawConsumedFractionFor($production);
                                $batchRawCost     = $batchRawCostIssued * $consumedFraction;

                                $batchTotalFg = (float) $production->receivings
                                    ->flatMap->details
                                    ->where('product_id', $product->id)
                                    ->sum('received_qty');

                                if ($batchTotalFg > 0 && $batchFgQty > 0) {
                                    $totalWeightedRawCost += ($batchRawCost / $batchTotalFg) * $batchFgQty;
                                }
                            }

                            $totalFgQty    += $batchFgQty;
                            $totalMfgValue += (float) $recDetail->manufacturing_cost * $batchFgQty;
                            $totalMfgQty   += $batchFgQty;
                        }

                        $rawCostPerPiece = $totalFgQty > 0 ? $totalWeightedRawCost / $totalFgQty : 0;
                        $mfgCostPerPiece = $totalMfgQty > 0 ? $totalMfgValue / $totalMfgQty : 0;
                    }

                    $costPrice = $rawCostPerPiece + $mfgCostPerPiece;

                    $stockInHand->push([
                        'product'   => $product->name,
                        'variation' => $var->sku ?? null,
                        'quantity'  => round($stockQty, 4),
                        'raw_cost'  => round($rawCostPerPiece, 2),
                        'mfg_cost'  => round($mfgCostPerPiece, 2),
                        'price'     => round($costPrice, 2),
                        'total'     => round($stockQty * $costPrice, 2),
                    ]);
                }
            }

            if (!$selected) {
                $stockInHand = $stockInHand->filter(fn($s) => $s['quantity'] != 0)->values();
            }
        }

        // ────────────────────────────────────────────────────────────────
        // 3. WASTAGE STOCK (Write-off register)
        // ────────────────────────────────────────────────────────────────
        if ($tab === 'WST') {
            $costingMethod = $request->costing_method ?? 'avg';

            $wastageRows = ProductionWastageReceivingDetail::with([
                    'product.measurementUnit',
                    'variation',
                    'unit',
                    'wastageReceiving.vendor',
                    'wastageReceiving.production',
                ])
                ->where('return_type', 'wastage')
                ->whereHas('wastageReceiving', fn($q) => $q->whereBetween('rec_date', [$from, $to]))
                ->get();

            $grouped = collect();

            foreach ($wastageRows as $row) {
                $key = $row->product_id . '-' . ($row->variation_id ?? '0');

                if (!$grouped->has($key)) {
                    $costPerUnit = $getPurchaseCost(
                        $row->product_id,
                        $costingMethod,
                        $row->variation_id
                    );

                    $grouped->put($key, [
                        'product'       => $row->product->name ?? '-',
                        'variation'     => $row->variation->sku ?? null,
                        'unit'          => $row->unit->shortcode
                            ?? $row->product->measurementUnit->shortcode
                            ?? '-',
                        'total_qty'     => 0,
                        'cost_per_unit' => round($costPerUnit, 2),
                        'total_cost'    => 0,
                        'entries'       => [],
                    ]);
                }

                $g = $grouped->get($key);
                $g['total_qty']  += (float) $row->quantity;
                $g['entries'][]   = [
                    'date'          => $row->wastageReceiving->rec_date,
                    'wrn_no'        => $row->wastageReceiving->grn_no ?? '-',
                    'vendor'        => $row->wastageReceiving->vendor->name ?? '-',
                    'production_id' => $row->wastageReceiving->production_id,
                    'qty'           => (float) $row->quantity,
                    'remarks'       => $row->remarks ?? '-',
                ];
                $grouped->put($key, $g);
            }

            $wastageStock = $grouped->map(function ($g) {
                $g['total_qty']  = round($g['total_qty'], 3);
                $g['total_cost'] = round($g['total_qty'] * $g['cost_per_unit'], 2);
                // Sort entries by date ascending
                usort($g['entries'], fn($a, $b) => strcmp($a['date'], $b['date']));
                return (object) $g;
            })->sortByDesc('total_qty')->values();
        }

        // ────────────────────────────────────────────────────────────────
        // 4. STOCK TRANSFERS
        // ────────────────────────────────────────────────────────────────
        if ($tab === 'STR') {
            $stockTransfers = StockTransferDetail::with([
                'product', 'variation',
                'transfer.fromLocation', 'transfer.toLocation',
            ])->whereHas('transfer', function ($query) use ($request, $from, $to) {
                $query->whereNull('deleted_at');
                if ($request->from_location_id) $query->where('from_location_id', $request->from_location_id);
                if ($request->to_location_id)   $query->where('to_location_id',   $request->to_location_id);
                $query->whereBetween('date', [$from, $to]);
            })->get()->map(fn($row) => [
                'date'      => $row->transfer->date ?? null,
                'reference' => $row->transfer->id   ?? null,
                'product'   => $row->product->name  ?? null,
                'variation' => $row->variation->sku ?? null,
                'from'      => $row->transfer->fromLocation->name ?? '',
                'to'        => $row->transfer->toLocation->name   ?? '',
                'quantity'  => $row->quantity,
            ]);
        }

        // ────────────────────────────────────────────────────────────────
        // 5. NON-MOVING ITEMS
        // ────────────────────────────────────────────────────────────────
        if ($tab === 'NMI') {
            $months    = (int) ($request->months ?? 3);
            $threshold = now()->subMonths($months)->toDateString();

            foreach ($allProducts as $product) {
                $variations = $product->variations->isNotEmpty()
                    ? $product->variations
                    : collect([(object)['id' => null, 'sku' => null, 'stock_quantity' => 0]]);

                foreach ($variations as $var) {
                    $stockQty = $getStockQty($product, $var);
                    if ($stockQty <= 0) continue;

                    $vid   = $var->id ?? null;
                    $dates = collect();

                    $lastPurchase = PurchaseInvoiceItem::where('item_id', $product->id)
                        ->when($vid, fn($q) => $q->where('variation_id', $vid))
                        ->with('invoice')->get()
                        ->max(fn($r) => $r->invoice->invoice_date ?? null);
                    if ($lastPurchase) $dates->push($lastPurchase);

                    $lastSale = SaleInvoiceItem::where('product_id', $product->id)
                        ->when($vid, fn($q) => $q->where('variation_id', $vid))
                        ->with('invoice')->get()
                        ->max(fn($r) => $r->invoice->date ?? null);
                    if ($lastSale) $dates->push($lastSale);

                    $lastProdOrder = ProductionDetail::where('product_id', $product->id)
                        ->when($vid, fn($q) => $q->where('variation_id', $vid))
                        ->with('production')->get()
                        ->max(fn($r) => $r->production->order_date ?? null);
                    if ($lastProdOrder) $dates->push($lastProdOrder);

                    $lastProdReceiving = ProductionReceivingDetail::where('product_id', $product->id)
                        ->when($vid, fn($q) => $q->where('variation_id', $vid))
                        ->with('receiving')->get()
                        ->max(fn($r) => $r->receiving->rec_date ?? null);
                    if ($lastProdReceiving) $dates->push($lastProdReceiving);

                    $lastProdReturn = ProductionReturnItem::where('product_id', $product->id)
                        ->when($vid, fn($q) => $q->where('variation_id', $vid))
                        ->with('productionReturn')->get()
                        ->max(fn($r) => $r->productionReturn->return_date ?? null);
                    if ($lastProdReturn) $dates->push($lastProdReturn);

                    $lastWastage = ProductionWastageReceivingDetail::where('product_id', $product->id)
                        ->when($vid, fn($q) => $q->where('variation_id', $vid))
                        ->with('wastageReceiving')->get()
                        ->max(fn($r) => $r->wastageReceiving->rec_date ?? null);
                    if ($lastWastage) $dates->push($lastWastage);

                    $lastDate = $dates->filter()->max();

                    if (!$lastDate || $lastDate <= $threshold) {
                        $nonMovingItems->push([
                            'product'       => $product->name,
                            'variation'     => $var->sku ?? null,
                            'stock_qty'     => round($stockQty, 2),
                            'last_date'     => $lastDate ?? 'Never',
                            'days_inactive' => $lastDate
                                ? Carbon::parse($lastDate)->diffInDays(now())
                                : null,
                        ]);
                    }
                }
            }

            $nonMovingItems = $nonMovingItems->sortByDesc('days_inactive')->values();
        }

        // ────────────────────────────────────────────────────────────────
        // 6. REORDER LEVEL
        // ────────────────────────────────────────────────────────────────
        if ($tab === 'ROL') {
            foreach ($allProducts as $product) {
                $reorderLevelSetting = (float) ($product->reorder_level ?? 0);
                if ($reorderLevelSetting <= 0) continue;

                $variations = $product->variations->isNotEmpty()
                    ? $product->variations
                    : collect([(object)['id' => null, 'sku' => null, 'stock_quantity' => 0]]);

                foreach ($variations as $var) {
                    $stockQty = $getStockQty($product, $var);

                    if ($stockQty <= $reorderLevelSetting) {
                        $reorderLevel->push([
                            'product'       => $product->name,
                            'variation'     => $var->sku ?? null,
                            'stock_inhand'  => round($stockQty, 2),
                            'reorder_level' => $reorderLevelSetting,
                            'min_order_qty' => $product->minimum_order_qty ?? 1,
                            'shortage'      => round(max(0, $reorderLevelSetting - $stockQty), 2),
                        ]);
                    }
                }
            }

            $reorderLevel = $reorderLevel->sortByDesc('shortage')->values();
        }

        return view('reports.inventory_reports', [
            'products'       => $allProducts,
            'tab'            => $tab,
            'itemLedger'     => $itemLedger,
            'stockInHand'    => $stockInHand,
            'wastageStock'   => $wastageStock,
            'stockTransfers' => $stockTransfers,
            'nonMovingItems' => $nonMovingItems,
            'reorderLevel'   => $reorderLevel,
            'from'           => $from,
            'to'             => $to,
            'locationId'     => $locationId,
            'locations'      => $locations,
        ]);
    }
}