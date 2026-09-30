<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Product;
use App\Models\StockLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Location-wise stock (quantity + cost) for the HJ flow.
 *
 * Every stock document (purchase, FG receiving, stock movement, sale,
 * sale return, stock adjustment) calls sync() with its signed lines, so
 * "how many of X are at location Y" is always SUM(stock_ledger.qty).
 *
 * Costing is weighted average of inbound cost (purchases, FG receivings,
 * opening / adjustments). Documents freeze the cost they used on their
 * own rows so re-posting later is stable.
 */
class Inventory
{
    /** Sources whose positive rows define an item's cost. */
    public const COST_SOURCES = [
        \App\Models\PurchaseInvoice::class,
        \App\Models\ProductionReceiving::class,
        \App\Models\StockAdjustment::class,
    ];

    // ─────────────────────────────────────────────────────────────
    // Ledger writes
    // ─────────────────────────────────────────────────────────────

    /**
     * Replace all ledger rows of a document.
     * $rows = [['product_id','variation_id','location_id','qty'(signed),'unit_cost','remarks'?], ...]
     */
    public static function sync(Model $source, string $date, array $rows): void
    {
        self::clear($source);

        $now    = now();
        $insert = [];
        foreach ($rows as $r) {
            $qty = round((float) ($r['qty'] ?? 0), 3);
            if ($qty == 0.0 || empty($r['product_id']) || empty($r['location_id'])) continue;

            $insert[] = [
                'date'         => $date,
                'product_id'   => (int) $r['product_id'],
                'variation_id' => !empty($r['variation_id']) ? (int) $r['variation_id'] : null,
                'location_id'  => (int) $r['location_id'],
                'qty'          => $qty,
                'unit_cost'    => round((float) ($r['unit_cost'] ?? 0), 4),
                'source_type'  => get_class($source),
                'source_id'    => $source->getKey(),
                'remarks'      => isset($r['remarks']) ? mb_substr($r['remarks'], 0, 250) : null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            StockLedger::insert($chunk);
        }
    }

    public static function clear(Model $source): void
    {
        StockLedger::where('source_type', get_class($source))
            ->where('source_id', $source->getKey())
            ->delete();
    }

    // ─────────────────────────────────────────────────────────────
    // Balances
    // ─────────────────────────────────────────────────────────────

    /**
     * Quantity of an item at a location. $variationId = null means the
     * product-level (no variation) line only.
     */
    public static function balance(int $locationId, int $productId, ?int $variationId = null, ?Model $exclude = null, ?string $asOf = null): float
    {
        return (float) StockLedger::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->when($variationId, fn ($q) => $q->where('variation_id', $variationId), fn ($q) => $q->whereNull('variation_id'))
            ->when($exclude, fn ($q) => $q->where(fn ($w) => $w
                ->where('source_type', '!=', get_class($exclude))
                ->orWhere('source_id', '!=', $exclude->getKey())))
            ->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))
            ->sum('qty');
    }

    /**
     * Block a document that would take a location below zero.
     * $lines = [['product_id','variation_id','qty'(positive = going out)], ...]
     */
    public static function assertAvailable(?int $locationId, array $lines, ?Model $exclude = null, string $field = 'items'): void
    {
        if (!$locationId || config('inventory.allow_negative')) return;

        $need = [];
        foreach ($lines as $l) {
            if (empty($l['product_id'])) continue;
            $key = $l['product_id'] . '-' . ($l['variation_id'] ?: 0);
            $need[$key] = ($need[$key] ?? 0) + (float) $l['qty'];
        }

        $location = Location::find($locationId);
        $errors   = [];
        foreach ($need as $key => $qty) {
            [$pid, $vid] = array_map('intval', explode('-', $key));
            $available = self::balance($locationId, $pid, $vid ?: null, $exclude);
            if ($available + 0.0001 < $qty) {
                $name = self::itemLabel($pid, $vid ?: null);
                $errors[] = "Not enough stock of {$name} at {$location?->name}: available "
                    . rtrim(rtrim(number_format($available, 3, '.', ''), '0'), '.')
                    . ', required ' . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . '.';
            }
        }

        if ($errors) {
            throw ValidationException::withMessages([$field => $errors]);
        }
    }

    public static function itemLabel(int $productId, ?int $variationId): string
    {
        $name = Product::whereKey($productId)->value('name') ?? ('#' . $productId);
        if ($variationId) {
            $sku = DB::table('product_variations')->where('id', $variationId)->value('sku');
            $name .= $sku ? " ({$sku})" : '';
        }
        return $name;
    }

    // ─────────────────────────────────────────────────────────────
    // Costing
    // ─────────────────────────────────────────────────────────────

    /** Weighted-average cost per unit of an item (as of a date). */
    public static function avgCost(int $productId, ?int $variationId = null, ?string $asOf = null, ?Model $exclude = null): float
    {
        $base = StockLedger::where('product_id', $productId)
            ->whereIn('source_type', self::COST_SOURCES)
            ->where('qty', '>', 0)
            ->where('unit_cost', '>', 0)
            ->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))
            ->when($exclude, fn ($q) => $q->where(fn ($w) => $w
                ->where('source_type', '!=', get_class($exclude))
                ->orWhere('source_id', '!=', $exclude->getKey())));

        $scopes = $variationId
            ? [fn ($q) => $q->where('variation_id', $variationId), fn ($q) => $q]
            : [fn ($q) => $q];

        foreach ($scopes as $scope) {
            $agg = $scope(clone $base)->selectRaw('SUM(qty * unit_cost) AS v, SUM(qty) AS q')->first();
            if ($agg && (float) $agg->q > 0) {
                return round((float) $agg->v / (float) $agg->q, 4);
            }
        }

        $product = Product::find($productId);
        return $product ? self::standardCost($product, $asOf) : 0.0;
    }

    /**
     * Cost when no history exists yet:
     *   finished good = CMT cost + consumption × fabric cost  (else cost price)
     *   anything else = cost price
     */
    public static function standardCost(Product $product, ?string $asOf = null): float
    {
        if ($product->item_type === 'fg') {
            $cost = (float) $product->cmt_cost;
            if ($product->fabric_id && (float) $product->consumption > 0 && $product->fabric_id != $product->id) {
                $cost += (float) $product->consumption * self::avgCost((int) $product->fabric_id, null, $asOf);
            }
            if ($cost > 0) return round($cost, 4);
        }

        return round((float) $product->cost_price, 4);
    }
}
