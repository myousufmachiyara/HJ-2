<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Production extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'vendor_id', 'category_id', 'order_date',
        'production_type', 'remarks', 'attachments', 'created_by',
    ];

    protected $casts = ['attachments' => 'array'];

    // ── Relationships ─────────────────────────────────────────────────

    public function vendor()      { return $this->belongsTo(ChartOfAccounts::class, 'vendor_id'); }
    public function category()    { return $this->belongsTo(ProductCategory::class, 'category_id'); }
    public function details()     { return $this->hasMany(ProductionDetail::class); }
    public function receivings()  { return $this->hasMany(ProductionReceiving::class); }
    public function returns()     { return $this->hasMany(ProductionReturnItem::class, 'production_id'); }

    public function wastageReceivings()
    {
        return $this->hasMany(ProductionWastageReceiving::class, 'production_id');
    }

    // ── Computed stats ────────────────────────────────────────────────

    public function getTotalRawGivenAttribute(): float
    {
        return (float) $this->details->sum('qty');
    }

    public function getTotalRawCostAttribute(): float
    {
        return (float) $this->details->sum(fn($d) => $d->qty * $d->rate);
    }

    public function getTotalFinishedReceivedAttribute(): float
    {
        return (float) $this->receivings->flatMap->details->sum('received_qty');
    }

    /**
     * True wastage — actual scraps/write-off, NO stock movement
     */
    public function getTotalWastageReturnedAttribute(): float
    {
        return (float) $this->wastageReceivings
            ->flatMap->details
            ->where('return_type', 'wastage')
            ->sum('quantity');
    }

    /**
     * Extra raw returned back to stock (unused, not waste)
     */
    public function getTotalExtraRawReturnedAttribute(): float
    {
        return (float) $this->wastageReceivings
            ->flatMap->details
            ->where('return_type', 'extra')
            ->sum('quantity');
    }

    /**
     * Expected raw consumed = sum(FG qty × product.consumption)
     */
    public function getExpectedConsumedAttribute(): float
    {
        $total = 0;
        foreach ($this->receivings as $rec) {
            foreach ($rec->details as $d) {
                $total += (float) $d->received_qty * (float) ($d->product->consumption ?? 0);
            }
        }
        return round($total, 4);
    }

    /**
     * Actual consumption per FG piece = total raw given / total FG received
     */
    public function getActualConsumptionPerPcAttribute(): float
    {
        $fg = $this->total_finished_received;
        return $fg > 0 ? round($this->total_raw_given / $fg, 4) : 0;
    }

    /**
     * Raw still at vendor (expected basis):
     *   Sent − Expected Consumed − Wastage (write-off) − Extra (back to stock)
     */
    public function getRawAtVendorExpectedAttribute(): float
    {
        return max(0,
            $this->total_raw_given
            - $this->expected_consumed
            - $this->total_wastage_returned
            - $this->total_extra_raw_returned
        );
    }

    /**
     * Raw still at vendor (actual basis):
     *   Sent − Actual Consumed − Wastage (write-off) − Extra (back to stock)
     */
    public function getRawAtVendorActualAttribute(): float
    {
        $actualConsumed = $this->actual_consumption_per_pc * $this->total_finished_received;
        return max(0,
            $this->total_raw_given
            - $actualConsumed
            - $this->total_wastage_returned
            - $this->total_extra_raw_returned
        );
    }

    /**
     * Average manufacturing (CMT) cost per FG piece
     */
    public function getAvgCmtCostAttribute(): float
    {
        $totalQty  = $this->total_finished_received;
        $totalCost = (float) $this->receivings->flatMap->details->sum(
            fn($d) => $d->received_qty * $d->manufacturing_cost
        );
        return $totalQty > 0 ? round($totalCost / $totalQty, 2) : 0;
    }

    /**
     * Avg total product cost/pc = raw cost/pc + avg CMT cost/pc
     */
    public function getAvgProductCostAttribute(): float
    {
        $fg = $this->total_finished_received;
        if ($fg <= 0) return 0;
        $rawCostPerPc = $this->total_raw_cost / $fg;
        return round($rawCostPerPc + $this->avg_cmt_cost, 2);
    }

    /**
     * Consumption variance % = (actual/pc − expected/pc) / expected/pc × 100
     * Positive = over-consumed, Negative = under-consumed
     */
    public function getConsumptionVariancePctAttribute(): ?float
    {
        $fg = $this->total_finished_received;
        if ($fg <= 0) return null;

        $expectedPerPc = $this->expected_consumed / $fg;
        if ($expectedPerPc <= 0) return null;

        $actual = $this->actual_consumption_per_pc;
        return round(($actual - $expectedPerPc) / $expectedPerPc * 100, 1);
    }
}