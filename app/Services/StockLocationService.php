<?php

namespace App\Services;

use App\Models\Location;
use App\Models\StockTransferDetail;
use App\Models\SaleInvoiceItem;
use App\Models\SaleReturnItem;

/**
 * Location-aware stock balance.
 *
 * Inventory is derived (nothing stored), so this computes on-hand AT ONE
 * LOCATION by summing stock transfers in/out of it. When the location is a
 * customer holder (locations.chart_of_account_id is set), it also nets that
 * customer's sales out and their sale returns back in.
 *
 * Customer link: sale_invoices.account_id -> chart_of_accounts.id
 *                (locations.chart_of_account_id -> chart_of_accounts.id)
 */
class StockLocationService
{
    /**
     * On-hand quantity of a product/variation AT a specific location.
     *
     * @param  string|null $asOf  Y-m-d exclusive upper bound (null = all time)
     */
    public static function balanceAt(int $locationId, int $productId, ?int $variationId = null, ?string $asOf = null): float
    {
        // ── transfers INTO this location (+) ──
        $transferredIn = (float) StockTransferDetail::where('product_id', $productId)
            ->when($variationId, fn($q) => $q->where('variation_id', $variationId))
            ->whereHas('transfer', function ($t) use ($locationId, $asOf) {
                $t->whereNull('deleted_at')
                  ->where('to_location_id', $locationId)
                  ->when($asOf, fn($q) => $q->where('date', '<', $asOf));
            })->sum('quantity');

        // ── transfers OUT of this location (−) ──
        $transferredOut = (float) StockTransferDetail::where('product_id', $productId)
            ->when($variationId, fn($q) => $q->where('variation_id', $variationId))
            ->whereHas('transfer', function ($t) use ($locationId, $asOf) {
                $t->whereNull('deleted_at')
                  ->where('from_location_id', $locationId)
                  ->when($asOf, fn($q) => $q->where('date', '<', $asOf));
            })->sum('quantity');

        $balance = $transferredIn - $transferredOut;

        // ── if this location belongs to a customer, net their sales/returns ──
        $location = Location::find($locationId);
        if ($location && $location->chart_of_account_id) {
            $balance -= self::customerSold($location->chart_of_account_id, $productId, $variationId, $asOf);
            $balance += self::customerReturned($location->chart_of_account_id, $productId, $variationId, $asOf);
        }

        return $balance;
    }

    /**
     * Everything a customer currently HOLDS.
     * Returns a collection of ['product_id','variation_id','quantity'] with qty != 0.
     */
    public static function customerHoldings(Location $customerLocation, ?string $asOf = null)
    {
        $rows = collect();

        // Every product/variation that has EITHER moved via transfer to/from this
        // customer, OR been sold/returned to this customer — so nothing is missed.
        $keys = collect();

        StockTransferDetail::whereHas('transfer', function ($t) use ($customerLocation) {
            $t->whereNull('deleted_at')
              ->where(function ($q) use ($customerLocation) {
                  $q->where('to_location_id', $customerLocation->id)
                    ->orWhere('from_location_id', $customerLocation->id);
              });
        })->get(['product_id', 'variation_id'])
          ->each(fn($r) => $keys->push([$r->product_id, $r->variation_id]));

        SaleInvoiceItem::whereHas('invoice', function ($i) use ($customerLocation) {
            $i->where('account_id', $customerLocation->chart_of_account_id);
        })->get(['product_id', 'variation_id'])
          ->each(fn($r) => $keys->push([$r->product_id, $r->variation_id]));

        $keys = $keys->unique(fn($k) => $k[0] . '-' . ($k[1] ?? '0'));

        foreach ($keys as [$productId, $variationId]) {
            $qty = self::balanceAt($customerLocation->id, $productId, $variationId, $asOf);
            if (round($qty, 4) != 0.0) {
                $rows->push([
                    'product_id'   => $productId,
                    'variation_id' => $variationId,
                    'quantity'     => round($qty, 4),
                ]);
            }
        }

        return $rows->values();
    }

    /**
     * Total quantity of a product/variation currently sitting at ANY customer.
     * The main-warehouse Stock-in-Hand subtracts this (the double-count fix).
     */
    public static function netAtCustomers(int $productId, ?int $variationId = null, ?string $asOf = null): float
    {
        $total = 0.0;
        foreach (Location::customers()->get() as $custLoc) {
            $total += self::balanceAt($custLoc->id, $productId, $variationId, $asOf);
        }
        return $total;
    }

    /**
     * Net quantity transferred OUT to customers minus what came back via return DC,
     * for a product/variation, ignoring sales (sales already reduce the global
     * derived stock through SaleInvoiceItem). Used by the warehouse fix so we only
     * subtract stock that physically left the warehouse via a DC but hasn't sold yet.
     */
    public static function netTransferredToCustomers(int $productId, ?int $variationId = null, ?string $asOf = null): float
    {
        $total = 0.0;
        foreach (Location::customers()->get() as $custLoc) {
            // transfers in (+) and out (−) only — no sales/returns netting here
            $in = (float) StockTransferDetail::where('product_id', $productId)
                ->when($variationId, fn($q) => $q->where('variation_id', $variationId))
                ->whereHas('transfer', function ($t) use ($custLoc, $asOf) {
                    $t->whereNull('deleted_at')
                      ->where('to_location_id', $custLoc->id)
                      ->when($asOf, fn($q) => $q->where('date', '<', $asOf));
                })->sum('quantity');

            $out = (float) StockTransferDetail::where('product_id', $productId)
                ->when($variationId, fn($q) => $q->where('variation_id', $variationId))
                ->whereHas('transfer', function ($t) use ($custLoc, $asOf) {
                    $t->whereNull('deleted_at')
                      ->where('from_location_id', $custLoc->id)
                      ->when($asOf, fn($q) => $q->where('date', '<', $asOf));
                })->sum('quantity');

            $total += ($in - $out);
        }
        return $total;
    }

    // ─────────────────────────────────────────────────────────────
    // Customer sales / returns (now wired to real tables).
    // ─────────────────────────────────────────────────────────────

    protected static function customerSold(int $customerAccountId, int $productId, ?int $variationId, ?string $asOf): float
    {
        return (float) SaleInvoiceItem::where('product_id', $productId)
            ->when($variationId, fn($q) => $q->where('variation_id', $variationId))
            ->whereHas('invoice', function ($inv) use ($customerAccountId, $asOf) {
                $inv->where('account_id', $customerAccountId)
                    ->when($asOf, fn($q) => $q->where('date', '<', $asOf));
                // (soft-deleted invoices are excluded automatically by the model's SoftDeletes)
            })->sum('quantity');
    }

    protected static function customerReturned(int $customerAccountId, int $productId, ?int $variationId, ?string $asOf): float
    {
        // NOTE: assumes sale_returns has an `account_id` customer column (mirrors
        // sale_invoices) and a `return_date`. If your column differs, change the two
        // marked lines below.
        return (float) SaleReturnItem::where('product_id', $productId)
            ->when($variationId, fn($q) => $q->where('variation_id', $variationId))
            ->whereHas('saleReturn', function ($ret) use ($customerAccountId, $asOf) {
                $ret->where('account_id', $customerAccountId)          // ← customer column on sale_returns
                    ->when($asOf, fn($q) => $q->where('return_date', '<', $asOf)); // ← date column on sale_returns
            })->sum('qty'); // sale_return_items quantity column is `qty`
    }
}