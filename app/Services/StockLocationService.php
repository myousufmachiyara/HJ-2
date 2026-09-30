<?php

namespace App\Services;

use App\Models\Location;
use App\Models\StockLedger;

/**
 * Location-aware stock balance — thin wrapper over the stock ledger
 * (see App\Services\Inventory). Kept for backwards compatibility.
 */
class StockLocationService
{
    public static function balanceAt(int $locationId, int $productId, ?int $variationId = null, ?string $asOf = null): float
    {
        return (float) StockLedger::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->when($variationId, fn ($q) => $q->where('variation_id', $variationId))
            ->when($asOf, fn ($q) => $q->where('date', '<', $asOf))
            ->sum('qty');
    }

    /** Everything a location currently holds: [product_id, variation_id, quantity]. */
    public static function customerHoldings(Location $location, ?string $asOf = null)
    {
        return StockLedger::where('location_id', $location->id)
            ->when($asOf, fn ($q) => $q->where('date', '<', $asOf))
            ->groupBy('product_id', 'variation_id')
            ->selectRaw('product_id, variation_id, ROUND(SUM(qty), 4) AS quantity')
            ->havingRaw('ROUND(SUM(qty), 4) <> 0')
            ->get()
            ->map(fn ($r) => [
                'product_id'   => $r->product_id,
                'variation_id' => $r->variation_id,
                'quantity'     => (float) $r->quantity,
            ])->values();
    }

    /** Quantity of an item sitting at all customer / marketplace locations. */
    public static function netAtCustomers(int $productId, ?int $variationId = null, ?string $asOf = null): float
    {
        return (float) StockLedger::whereIn('location_id', Location::customers()->pluck('id'))
            ->where('product_id', $productId)
            ->when($variationId, fn ($q) => $q->where('variation_id', $variationId))
            ->when($asOf, fn ($q) => $q->where('date', '<', $asOf))
            ->sum('qty');
    }
}
