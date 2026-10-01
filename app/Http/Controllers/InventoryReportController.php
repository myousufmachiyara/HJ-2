<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionReceivingDetail;
use App\Models\ProductionWastageReceivingDetail;
use App\Models\PurchaseInvoiceItem;
use App\Models\SaleInvoice;
use App\Models\StockLedger;
use App\Models\StockTransferDetail;
use App\Services\Inventory;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Inventory reports.
 *
 * Every quantity here comes from the stock ledger (App\Services\Inventory),
 * i.e. the same numbers the documents check against: purchases at their
 * drop-off location, FG receivings (+ fabric consumed at the CMT), stock
 * movements, sales / POS / returns at their location, purchase returns and
 * stock adjustments. Values use weighted-average cost.
 *
 * Wastage (WST) still reads the production module, which is not yet on the
 * stock ledger.
 */
class InventoryReportController extends Controller
{
    public function inventoryReports(Request $request)
    {
        $tab        = $request->tab ?? 'IL';
        $selected   = $request->item_id ?? null;
        $from       = $request->from_date ?? now()->startOfMonth()->toDateString();
        $to         = $request->to_date   ?? now()->toDateString();
        $locationId = $request->location_id ?: null;             // optional location filter (IL / SR / ROL / NMI)
        $itemType   = $request->item_type ?: null;               // fg | raw | service
        $locations  = Location::orderByRaw("FIELD(type,'warehouse','customer','vendor')")->orderBy('name')->get();

        [$productId, $variationId] = $this->parseItem($selected);

        $allProducts = Product::with('variations')->orderBy('name')->get();

        $itemLedger       = collect();
        $stockInHand      = collect();
        $wastageStock     = collect();
        $stockTransfers   = collect();
        $nonMovingItems   = collect();
        $reorderLevel     = collect();
        $locationStock    = collect();
        $atCustomersTotal = 0.0;
        $atVendorsTotal   = 0.0;

        // purchase-cost helper, used only by the production wastage tab
        $getPurchaseCost = function (int $itemId, string $method, ?int $varId = null) {
            $pq = PurchaseInvoiceItem::where('item_id', $itemId)
                ->when($varId && PurchaseInvoiceItem::where('item_id', $itemId)->where('variation_id', $varId)->exists(),
                    fn ($q) => $q->where('variation_id', $varId));
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

        // Ledger rows limited by the common filters
        $ledger = fn () => StockLedger::query()
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->when($itemType, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('item_type', $itemType)));

        // ────────────────────────────────────────────────────────────────
        // 1. ITEM LEDGER — every movement of one item, with running balance
        // ────────────────────────────────────────────────────────────────
        if ($tab === 'IL' && $productId) {
            $scope = fn () => $ledger()->where('product_id', $productId)
                ->when($variationId, fn ($q) => $q->where('variation_id', $variationId));

            $opening = (float) $scope()->where('date', '<', $from)->sum('qty');
            $rows    = $scope()->with(['location:id,name', 'product:id,name', 'variation:id,sku'])
                ->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();
            $docNos  = Inventory::documentNumbers($rows);

            $balance = $opening;
            if (round($opening, 3) != 0.0) {
                $itemLedger->push([
                    'date' => $from, 'type' => 'Opening Balance', 'document' => '',
                    'description' => 'Brought forward (before ' . Carbon::parse($from)->format('d-M-Y') . ')',
                    'location' => $locationId ? ($locations->firstWhere('id', $locationId)->name ?? '') : 'All locations',
                    'product' => $allProducts->firstWhere('id', $productId)->name ?? '', 'variation' => null,
                    'qty_in' => max(0, $opening), 'qty_out' => max(0, -$opening), 'rate' => 0, 'balance' => $balance,
                ]);
            }
            foreach ($rows as $r) {
                $qty      = (float) $r->qty;
                $balance += $qty;
                $itemLedger->push([
                    'date'        => $r->date->toDateString(),
                    'type'        => Inventory::sourceLabel($r->source_type, $qty, $r->remarks),
                    'document'    => $docNos[$r->source_type][$r->source_id] ?? ('#' . $r->source_id),
                    'description' => $r->remarks,
                    'location'    => $r->location->name ?? '-',
                    'product'     => $r->product->name ?? '-',
                    'variation'   => $r->variation->sku ?? null,
                    'qty_in'      => $qty > 0 ? $qty : 0,
                    'qty_out'     => $qty < 0 ? -$qty : 0,
                    'rate'        => (float) $r->unit_cost,
                    'balance'     => $balance,
                ]);
            }
        }

        // ────────────────────────────────────────────────────────────────
        // 2. STOCK IN HAND — qty + value per item (all locations or one)
        // ────────────────────────────────────────────────────────────────
        if ($tab === 'SR') {
            $cost = Inventory::avgCostResolver($to);

            // CMT (manufacturing) cost per FG unit, from receivings — to split raw vs making cost
            $mfg = ProductionReceivingDetail::whereHas('receiving', fn ($q) => $q->where('rec_date', '<=', $to))
                ->groupBy('product_id')
                ->selectRaw('product_id, SUM(manufacturing_cost * received_qty) / NULLIF(SUM(received_qty), 0) AS c')
                ->pluck('c', 'product_id');

            $rows = $ledger()->where('date', '<=', $to)
                ->when($productId, fn ($q) => $q->where('product_id', $productId))
                ->when($variationId, fn ($q) => $q->where('variation_id', $variationId))
                ->with(['product:id,name,item_type,cmt_cost', 'variation:id,sku'])
                ->groupBy('product_id', 'variation_id')
                ->selectRaw('product_id, variation_id, SUM(qty) AS qty')
                ->havingRaw('ROUND(SUM(qty), 3) <> 0')
                ->get();

            foreach ($rows as $r) {
                $price   = $cost($r->product_id, $r->variation_id);
                $mfgCost = $r->product && $r->product->item_type === 'fg'
                    ? min($price, (float) ($mfg[$r->product_id] ?? $r->product->cmt_cost ?? 0))
                    : 0;
                $stockInHand->push([
                    'product'   => $r->product->name ?? ('#' . $r->product_id),
                    'variation' => $r->variation->sku ?? null,
                    'item_type' => $r->product->item_type ?? null,
                    'quantity'  => round((float) $r->qty, 3),
                    'raw_cost'  => round($price - $mfgCost, 2),
                    'mfg_cost'  => round($mfgCost, 2),
                    'price'     => round($price, 2),
                    'total'     => round($price * (float) $r->qty, 2),
                ]);
            }
            $stockInHand = $stockInHand->sortBy('product')->values();

            if (!$locationId) {
                $held = fn ($ids) => (float) StockLedger::whereIn('location_id', $ids)->where('date', '<=', $to)
                    ->when($productId, fn ($q) => $q->where('product_id', $productId))
                    ->when($variationId, fn ($q) => $q->where('variation_id', $variationId))
                    ->when($itemType, fn ($q) => $q->whereHas('product', fn ($p) => $p->where('item_type', $itemType)))
                    ->sum('qty');
                $atCustomersTotal = $held(Location::customers()->pluck('id'));
                $atVendorsTotal   = $held(Location::vendors()->pluck('id'));
            }
        }

        // ────────────────────────────────────────────────────────────────
        // 3. WASTAGE (production module — unchanged)
        // ────────────────────────────────────────────────────────────────
        if ($tab === 'WST') {
            $costingMethod = $request->costing_method ?? 'avg';

            $wastageRows = ProductionWastageReceivingDetail::with([
                    'product.measurementUnit', 'variation', 'unit',
                    'wastageReceiving.vendor', 'wastageReceiving.production',
                ])
                ->where('return_type', 'wastage')
                ->whereHas('wastageReceiving', fn($q) => $q->whereBetween('rec_date', [$from, $to]))
                ->get();

            $grouped = collect();

            foreach ($wastageRows as $row) {
                $key = $row->product_id . '-' . ($row->variation_id ?? '0');

                if (!$grouped->has($key)) {
                    $costPerUnit = $getPurchaseCost($row->product_id, $costingMethod, $row->variation_id);
                    $grouped->put($key, [
                        'product'       => $row->product->name ?? '-',
                        'variation'     => $row->variation->sku ?? null,
                        'unit'          => $row->unit->shortcode ?? $row->product->measurementUnit->shortcode ?? '-',
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
                usort($g['entries'], fn($a, $b) => strcmp($a['date'], $b['date']));
                return (object) $g;
            })->sortByDesc('total_qty')->values();
        }

        // ────────────────────────────────────────────────────────────────
        // 4. STOCK MOVEMENTS
        // ────────────────────────────────────────────────────────────────
        if ($tab === 'STR') {
            $stockTransfers = StockTransferDetail::with([
                'product', 'variation', 'transfer.fromLocation', 'transfer.toLocation',
            ])->whereHas('transfer', function ($query) use ($request, $from, $to) {
                if ($request->from_location_id) $query->where('from_location_id', $request->from_location_id);
                if ($request->to_location_id)   $query->where('to_location_id',   $request->to_location_id);
                $query->whereBetween('date', [$from, $to]);
            })->get()->map(fn ($row) => [
                'date'      => $row->transfer->date ?? null,
                'reference' => $row->transfer->id   ?? null,
                'type'      => $row->transfer->type ?? 'transfer',
                'product'   => $row->product->name  ?? null,
                'variation' => $row->variation->sku ?? null,
                'from'      => $row->transfer->fromLocation->name ?? '',
                'to'        => $row->transfer->toLocation->name   ?? '',
                'quantity'  => (float) $row->quantity,
                'unit_cost' => (float) $row->unit_cost,
                'value'     => round((float) $row->quantity * (float) $row->unit_cost, 2),
            ])->sortBy('date')->values();
        }

        // ────────────────────────────────────────────────────────────────
        // 5. NON-MOVING ITEMS — in stock, but not sold for N months
        // ────────────────────────────────────────────────────────────────
        if ($tab === 'NMI') {
            $months    = (int) ($request->months ?? 3);
            $threshold = now()->subMonths($months)->toDateString();

            $stock = $ledger()->with(['product:id,name', 'variation:id,sku'])
                ->groupBy('product_id', 'variation_id')
                ->selectRaw('product_id, variation_id, SUM(qty) AS qty, MAX(date) AS last_move')
                ->havingRaw('SUM(qty) > 0')->get();

            $lastSale = StockLedger::where('source_type', SaleInvoice::class)->where('qty', '<', 0)
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
                ->groupBy('product_id', 'variation_id')
                ->selectRaw("product_id, variation_id, MAX(date) AS d")->get()
                ->mapWithKeys(fn ($r) => [$r->product_id . '-' . ($r->variation_id ?? 0) => $r->d]);

            foreach ($stock as $r) {
                $sold = $lastSale[$r->product_id . '-' . ($r->variation_id ?? 0)] ?? null;
                if ($sold && $sold > $threshold) continue;
                $nonMovingItems->push([
                    'product'       => $r->product->name ?? '-',
                    'variation'     => $r->variation->sku ?? null,
                    'stock_qty'     => round((float) $r->qty, 2),
                    'last_date'     => $sold ?? 'Never',
                    'last_move'     => $r->last_move,
                    'days_inactive' => $sold ? Carbon::parse($sold)->diffInDays(now()) : null,
                ]);
            }
            $nonMovingItems = $nonMovingItems->sortByDesc(fn ($r) => $r['days_inactive'] ?? PHP_INT_MAX)->values();
        }

        // ────────────────────────────────────────────────────────────────
        // 6. REORDER LEVEL — total stock (all locations) vs reorder level
        // ────────────────────────────────────────────────────────────────
        if ($tab === 'ROL') {
            $qty = $ledger()->groupBy('product_id', 'variation_id')
                ->selectRaw('product_id, variation_id, SUM(qty) AS qty')->get()
                ->mapWithKeys(fn ($r) => [$r->product_id . '-' . ($r->variation_id ?? 0) => (float) $r->qty]);

            foreach ($allProducts as $product) {
                $level = (float) ($product->reorder_level ?? 0);
                if ($level <= 0 || ($itemType && $product->item_type !== $itemType)) continue;

                $vars = $product->variations->isNotEmpty() ? $product->variations : collect([(object) ['id' => null, 'sku' => null]]);
                foreach ($vars as $var) {
                    $stockQty = $qty[$product->id . '-' . ($var->id ?? 0)] ?? 0;
                    if ($stockQty <= $level) {
                        $reorderLevel->push([
                            'product'       => $product->name,
                            'variation'     => $var->sku ?? null,
                            'stock_inhand'  => round($stockQty, 2),
                            'reorder_level' => $level,
                            'min_order_qty' => $product->minimum_order_qty ?? 1,
                            'shortage'      => round(max(0, $level - $stockQty), 2),
                        ]);
                    }
                }
            }
            $reorderLevel = $reorderLevel->sortByDesc('shortage')->values();
        }

        if ($tab === 'LOC') {
            // Location-wise stock now comes straight from the stock ledger
            // (purchases at drop-off, FG receivings, fabric consumed at CMT,
            // stock movements, sales / returns at their location, adjustments).
            $selectedLocationId = $request->stock_location_id ?: Location::defaultId();

            if ($selectedLocationId) {
                $cost = Inventory::avgCostResolver($to);
                $locationStock = StockLedger::with(['product:id,name', 'variation:id,sku'])
                    ->where('location_id', $selectedLocationId)
                    ->when($to, fn ($q) => $q->where('date', '<=', $to))
                    ->groupBy('product_id', 'variation_id')
                    ->selectRaw('product_id, variation_id, SUM(qty) AS qty')
                    ->havingRaw('ROUND(SUM(qty), 3) <> 0')
                    ->get()
                    ->map(function ($r) use ($cost) {
                        $unit = $cost($r->product_id, $r->variation_id);
                        return [
                            'product'   => $r->product->name ?? ('#' . $r->product_id),
                            'variation' => $r->variation->sku ?? null,
                            'quantity'  => round((float) $r->qty, 3),
                            'unit_cost' => $unit,
                            'value'     => round($unit * (float) $r->qty, 2),
                        ];
                    })
                    ->sortBy('product')->values();
            }
        }

        return view('reports.inventory_reports', [
            'products'         => $allProducts,
            'tab'              => $tab,
            'itemLedger'       => $itemLedger,
            'stockInHand'      => $stockInHand,
            'wastageStock'     => $wastageStock,
            'stockTransfers'   => $stockTransfers,
            'nonMovingItems'   => $nonMovingItems,
            'reorderLevel'     => $reorderLevel,
            'locationStock'    => $locationStock,       // ← NEW
            'atCustomersTotal' => round($atCustomersTotal, 2),
            'atVendorsTotal'   => round($atVendorsTotal, 2),
            'itemType'         => $itemType,
            'from'             => $from,
            'to'               => $to,
            'locationId'       => $locationId,
            'locations'        => $locations,
            'selectedLocationId' => $request->stock_location_id ?? Location::defaultId(), // ← NEW
        ]);
    }

    private function parseItem(?string $selected): array
    {
        if (!$selected) return [null, null];
        $parts = explode('-', $selected, 2);
        if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            return [(int) $parts[0], (int) $parts[1]];
        }
        return [(int) $selected, null];
    }
}
