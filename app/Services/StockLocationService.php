<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Location;
use App\Models\StockTransferDetail;

/**
 * Location-aware stock balance.
 *
 * Your inventory is derived (nothing stored), so this computes on-hand AT ONE
 * LOCATION by summing stock transfers in/out of it. When the location is a
 * customer holder (locations.chart_of_account_id is set), it also nets that
 * customer's sales out and their sale returns back in.
 *
 * NOTE: the sale/sale-return piece is wired in step 7 once the sale_invoices
 * customer column is confirmed. The two clearly-marked stubs below are the only
 * things that change then.
 */
class StockLocationService
{
    /**
     * On-hand quantity of a product/variation AT a specific location.
     *
     * @param  int       $locationId
     * @param  int       $productId
     * @param  int|null  $variationId
     * @param  string|null $asOf  Y-m-d exclusive upper bound (null = all time)
     */
    public static function balanceAt(int $locationId, int $productId, ?int $variationId = null, ?string $asOf = null): float
    {
        // ── transfers INTO this location (+) ──
        $inQ = StockTransferDetail::where('product_id', $productId)
            ->when($variationId, fn($q) => $q->where('variation_id', $variationId))
            ->whereHas('transfer', function ($t) use ($locationId, $asOf) {
                $t->whereNull('deleted_at')
                  ->where('to_location_id', $locationId)
                  ->when($asOf, fn($q) => $q->where('date', '<', $asOf));
            });
        $transferredIn = (float) $inQ->sum('quantity');

        // ── transfers OUT of this location (−) ──
        $outQ = StockTransferDetail::where('product_id', $productId)
            ->when($variationId, fn($q) => $q->where('variation_id', $variationId))
            ->whereHas('transfer', function ($t) use ($locationId, $asOf) {
                $t->whereNull('deleted_at')
                  ->where('from_location_id', $locationId)
                  ->when($asOf, fn($q) => $q->where('date', '<', $asOf));
            });
        $transferredOut = (float) $outQ->sum('quantity');

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
     * Net quantity a customer currently HOLDS across all their products.
     * Returns a collection keyed by "productId-variationId" => qty.
     * Used by the Customer Stock report tab.
     */
    public static function customerHoldings(Location $customerLocation, ?string $asOf = null)
    {
        $rows = collect();

        // gather every product/variation ever transferred to this customer
        $lines = StockTransferDetail::whereHas('transfer', function ($t) use ($customerLocation) {
            $t->whereNull('deleted_at')
              ->where(function ($q) use ($customerLocation) {
                  $q->where('to_location_id', $customerLocation->id)
                    ->orWhere('from_location_id', $customerLocation->id);
              });
        })->get(['product_id', 'variation_id'])
          ->unique(fn($r) => $r->product_id . '-' . ($r->variation_id ?? '0'));

        foreach ($lines as $line) {
            $qty = self::balanceAt($customerLocation->id, $line->product_id, $line->variation_id, $asOf);
            if (round($qty, 4) != 0.0) {
                $rows->push([
                    'product_id'   => $line->product_id,
                    'variation_id' => $line->variation_id,
                    'quantity'     => round($qty, 4),
                ]);
            }
        }

        return $rows->values();
    }

    /**
     * Total quantity of a product/variation currently sitting at ANY customer.
     * Your main-warehouse Stock-in-Hand must subtract this so the same units
     * don't show in both places (the "double-count fix", step 4).
     */
    public static function netAtCustomers(int $productId, ?int $variationId = null, ?string $asOf = null): float
    {
        $total = 0.0;
        foreach (Location::customers()->get() as $custLoc) {
            $total += self::balanceAt($custLoc->id, $productId, $variationId, $asOf);
        }
        return $total;
    }

    // ─────────────────────────────────────────────────────────────
    // STUBS — completed in step 7 (need sale_invoices customer column).
    // Until then they return 0, so transfers still work and no sale is
    // wrongly deducted. Filling these in makes the sale invoice reduce
    // the customer's held stock automatically.
    // ─────────────────────────────────────────────────────────────

    protected static function customerSold(int $customerAccountId, int $productId, ?int $variationId, ?string $asOf): float
    {
        // TODO step 7: sum SaleInvoiceItem.quantity where invoice belongs to $customerAccountId
        // (joined via the sale_invoices customer FK), variation-aware, asOf on invoice date.
        return 0.0;
    }

    protected static function customerReturned(int $customerAccountId, int $productId, ?int $variationId, ?string $asOf): float
    {
        // TODO step 7: sum SaleReturnItem.qty where the return belongs to $customerAccountId,
        // variation-aware, asOf on return_date.
        return 0.0;
    }
}