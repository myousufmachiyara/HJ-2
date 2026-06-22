<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Production;
use App\Models\ProductionReceiving;
use App\Models\ProductionReturn;
use App\Models\ProductionWastageReceiving;
use App\Models\Product;
use App\Models\ChartOfAccounts;
use Carbon\Carbon;

class ProductionReportController extends Controller
{
    public function productionReports(Request $request)
    {
        $tab  = $request->get('tab', 'RMI');
        $from = $request->get('from_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to   = $request->get('to_date',   Carbon::now()->format('Y-m-d'));

        $rawIssued        = collect();
        $produced         = collect();
        $costings         = collect();
        $vendorRawBalance = collect();
        $returnReport     = collect();
        $wastageReport    = collect();
        $deliveryReport   = collect();
        $orderSummary     = collect();

        // ── 1. Production Orders (RMI) ────────────────────────────────
        if ($tab === 'RMI') {
            $productions = Production::with([
                    'vendor',
                    'details.product.measurementUnit',
                    'receivings.details.product',
                    'wastageReceivings.details',
                ])
                ->whereBetween('order_date', [$from, $to])
                ->orderBy('order_date', 'desc')
                ->get();

            $rawIssued = $productions->map(function ($prod) {
                $variancePct = $prod->consumption_variance_pct;

                return (object)[
                    'id'                   => $prod->id,
                    'date'                 => $prod->order_date,
                    'vendor'               => $prod->vendor->name ?? '-',
                    'type'                 => ucfirst(str_replace('_', ' ', $prod->production_type)),
                    'total_raw_given'      => $prod->total_raw_given,
                    'total_raw_cost'       => $prod->total_raw_cost,
                    'total_fg_received'    => $prod->total_finished_received,
                    'expected_consumed'    => $prod->expected_consumed,
                    'actual_con_per_pc'    => $prod->actual_consumption_per_pc,
                    // Split wastage
                    'wastage_returned'     => $prod->total_wastage_returned,      // write-off only
                    'extra_returned'       => $prod->total_extra_raw_returned,    // back to stock
                    'raw_at_vendor'        => $prod->raw_at_vendor_expected,
                    'raw_at_vendor_actual' => $prod->raw_at_vendor_actual,
                    'avg_cmt_cost'         => $prod->avg_cmt_cost,
                    'avg_product_cost'     => $prod->avg_product_cost,
                    'variance_pct'         => $variancePct,
                    'variance_alert'       => $variancePct !== null && abs($variancePct) > 10,
                    'variance_critical'    => $variancePct !== null && abs($variancePct) > 25,
                    'raw_details'          => $prod->details,
                ];
            });
        }

        // ── 2. Production Receiving (PR) ──────────────────────────────
        if ($tab === 'PR') {
            $produced = ProductionReceiving::with([
                    'vendor',
                    'production',
                    'details.product.measurementUnit',
                    'details.variation',
                ])
                ->whereBetween('rec_date', [$from, $to])
                ->orderBy('rec_date', 'desc')
                ->get()
                ->flatMap(function ($rec) {
                    return $rec->details->map(fn($d) => (object)[
                        'date'          => $rec->rec_date,
                        'grn_no'        => $rec->grn_no,
                        'vendor'        => $rec->vendor->name ?? '-',
                        'production_id' => $rec->production_id ?? '-',
                        'item_name'     => $d->product->name ?? '-',
                        'variation'     => $d->variation->sku ?? '-',
                        'unit'          => $d->product->measurementUnit->shortcode ?? '-',
                        'qty'           => $d->received_qty,
                        'mfg_cost'      => $d->manufacturing_cost,
                        'total'         => $d->received_qty * $d->manufacturing_cost,
                    ]);
                });
        }

        // ── 3. Product Costing (CR) ───────────────────────────────────
        if ($tab === 'CR') {
            $allReceivings = ProductionReceiving::with([
                    'details.product',
                    'production.details',
                ])
                ->whereBetween('rec_date', [$from, $to])
                ->get();

            $grouped = collect();

            foreach ($allReceivings as $rec) {
                $prod           = $rec->production;
                $totalRawCost   = $prod ? (float) $prod->details->sum(fn($d) => $d->qty * $d->rate) : 0;
                $totalFgForProd = $prod
                    ? (float) $prod->receivings->flatMap->details->sum('received_qty')
                    : 0;
                $rawCostPerPc = $totalFgForProd > 0 ? $totalRawCost / $totalFgForProd : 0;

                foreach ($rec->details as $d) {
                    $pid = $d->product_id;
                    if (!$grouped->has($pid)) {
                        $grouped->put($pid, [
                            'name'           => $d->product->name ?? '-',
                            'total_qty'      => 0,
                            'total_mfg_cost' => 0,
                            'total_raw_cost' => 0,
                            'cmt_cost_set'   => (float) ($d->product->cmt_cost ?? 0),
                        ]);
                    }
                    $g = $grouped->get($pid);
                    $g['total_qty']      += (float) $d->received_qty;
                    $g['total_mfg_cost'] += (float) $d->received_qty * (float) $d->manufacturing_cost;
                    $g['total_raw_cost'] += (float) $d->received_qty * $rawCostPerPc;
                    $grouped->put($pid, $g);
                }
            }

            $costings = $grouped->map(fn($g) => (object)[
                'product_name'   => $g['name'],
                'total_qty'      => $g['total_qty'],
                'avg_mfg_cost'   => $g['total_qty'] > 0 ? round($g['total_mfg_cost'] / $g['total_qty'], 2) : 0,
                'avg_raw_cost'   => $g['total_qty'] > 0 ? round($g['total_raw_cost'] / $g['total_qty'], 2) : 0,
                'avg_total_cost' => $g['total_qty'] > 0
                    ? round(($g['total_mfg_cost'] + $g['total_raw_cost']) / $g['total_qty'], 2) : 0,
                'total_cost'     => $g['total_mfg_cost'] + $g['total_raw_cost'],
                'cmt_cost_set'   => $g['cmt_cost_set'],
            ])->values();
        }

        // ── 4. Vendor Raw Balance (VRB) ───────────────────────────────
        if ($tab === 'VRB') {
            $vendors = ChartOfAccounts::where('account_type', 'vendor')->get();

            $vendorRawBalance = $vendors->map(function ($vendor) use ($to) {
                $productions = Production::with([
                        'details.product.measurementUnit',
                        'receivings.details.product',
                        'wastageReceivings.details',
                    ])
                    ->where('vendor_id', $vendor->id)
                    ->where('order_date', '<=', $to)
                    ->get();

                $balance = collect();

                foreach ($productions as $prod) {
                    // Sent
                    foreach ($prod->details as $d) {
                        $pid = $d->product_id;
                        if (!$balance->has($pid)) {
                            $balance->put($pid, [
                                'product'          => $d->product->name ?? '-',
                                'unit'             => $d->product->measurementUnit->shortcode ?? '-',
                                'sent'             => 0,
                                'expected_consumed'=> 0,
                                'actual_consumed'  => 0,
                                'extra_returned'   => 0,  // back to stock
                                'wastage_returned' => 0,  // write-off
                            ]);
                        }
                        $row = $balance->get($pid);
                        $row['sent'] += (float) $d->qty;
                        $balance->put($pid, $row);
                    }

                    // Expected consumed
                    foreach ($prod->receivings as $rec) {
                        foreach ($rec->details as $rd) {
                            $fgQty       = (float) $rd->received_qty;
                            $consumption = (float) ($rd->product->consumption ?? 0);
                            $totalRaw    = (float) $prod->details->sum('qty');

                            foreach ($prod->details as $d) {
                                $pid   = $d->product_id;
                                $ratio = $totalRaw > 0 ? (float) $d->qty / $totalRaw : 0;
                                if ($balance->has($pid)) {
                                    $row = $balance->get($pid);
                                    $row['expected_consumed'] += $fgQty * $consumption * $ratio;
                                    $balance->put($pid, $row);
                                }
                            }
                        }
                    }

                    // Actual consumed
                    $actualConPerPc = $prod->actual_consumption_per_pc;
                    $totalFg        = $prod->total_finished_received;
                    $totalRaw       = (float) $prod->details->sum('qty');

                    foreach ($prod->details as $d) {
                        $pid   = $d->product_id;
                        $ratio = $totalRaw > 0 ? (float) $d->qty / $totalRaw : 0;
                        if ($balance->has($pid)) {
                            $row = $balance->get($pid);
                            $row['actual_consumed'] += $actualConPerPc * $totalFg * $ratio;
                            $balance->put($pid, $row);
                        }
                    }

                    // Wastage split: extra vs wastage
                    foreach ($prod->wastageReceivings as $wr) {
                        foreach ($wr->details as $wd) {
                            $pid = $wd->product_id;
                            if ($balance->has($pid)) {
                                $row = $balance->get($pid);
                                if (($wd->return_type ?? 'extra') === 'extra') {
                                    $row['extra_returned']   += (float) $wd->quantity;
                                } else {
                                    $row['wastage_returned'] += (float) $wd->quantity;
                                }
                                $balance->put($pid, $row);
                            }
                        }
                    }
                }

                $rows = $balance->map(function ($item) {
                    $expConsumed   = round($item['expected_consumed'], 4);
                    $actConsumed   = round($item['actual_consumed'],   4);
                    $extraReturned = round($item['extra_returned'],    4);
                    $wastage       = round($item['wastage_returned'],  4);
                    $sent          = round($item['sent'],              4);

                    // Raw at vendor = sent − consumed − both types of returns
                    $remainingExp = max(0, $sent - $expConsumed - $extraReturned - $wastage);
                    $remainingAct = max(0, $sent - $actConsumed - $extraReturned - $wastage);

                    $variancePct = $expConsumed > 0
                        ? round(($actConsumed - $expConsumed) / $expConsumed * 100, 1)
                        : null;

                    return (object)[
                        'product'            => $item['product'],
                        'unit'               => $item['unit'],
                        'sent'               => $sent,
                        'expected_consumed'  => $expConsumed,
                        'actual_consumed'    => $actConsumed,
                        'extra_returned'     => $extraReturned,
                        'wastage_returned'   => $wastage,
                        'remaining_expected' => $remainingExp,
                        'remaining_actual'   => $remainingAct,
                        'variance_pct'       => $variancePct,
                        'alert'              => $variancePct !== null && abs($variancePct) > 10,
                        'critical'           => $variancePct !== null && abs($variancePct) > 25,
                    ];
                })->values()->filter(fn($r) => $r->sent > 0);

                return (object)[
                    'vendor'  => $vendor->name,
                    'balance' => $rows,
                    'total'   => $rows->count(),
                ];
            })->filter(fn($v) => $v->total > 0)->values();
        }

        // ── 5. Production Returns (RTN) ───────────────────────────────
        if ($tab === 'RTN') {
            $returns = ProductionReturn::with([
                    'vendor', 'items.product', 'items.variation', 'items.unit',
                ])
                ->whereBetween('return_date', [$from, $to])
                ->orderBy('return_date', 'desc')
                ->get();

            $returnReport = $returns->flatMap(function ($ret) {
                return $ret->items->map(fn($item) => (object)[
                    'date'          => $ret->return_date,
                    'return_id'     => $ret->id,
                    'vendor'        => $ret->vendor->name ?? '-',
                    'item_name'     => $item->product->name ?? '-',
                    'variation'     => $item->variation->sku ?? '-',
                    'production_id' => $item->production_id ?? '-',
                    'qty'           => $item->quantity,
                    'unit'          => $item->unit->shortcode ?? '-',
                    'rate'          => $item->price,
                    'total'         => $item->quantity * $item->price,
                ]);
            });
        }

        // ── 6. Wastage Returns (WST) ──────────────────────────────────
        if ($tab === 'WST') {
            $wastages = ProductionWastageReceiving::with([
                    'vendor', 'production',
                    'details.product.measurementUnit',
                    'details.variation', 'details.unit',
                ])
                ->whereBetween('rec_date', [$from, $to])
                ->orderBy('rec_date', 'desc')
                ->get();

            $wastageReport = $wastages->flatMap(function ($w) {
                return $w->details->map(fn($d) => (object)[
                    'date'          => $w->rec_date,
                    'wrn_no'        => $w->grn_no,
                    'vendor'        => $w->vendor->name ?? '-',
                    'production_id' => $w->production_id ?? '-',
                    'item_name'     => $d->product->name ?? '-',
                    'variation'     => $d->variation->sku ?? '-',
                    'unit'          => $d->unit->shortcode ?? $d->product->measurementUnit->shortcode ?? '-',
                    'qty'           => $d->quantity,
                    'return_type'   => $d->return_type ?? 'extra',
                    'remarks'       => $d->remarks ?? '-',
                ]);
            });
        }

        // ── 7. Delivery Tracking (DLV) ────────────────────────────────
        if ($tab === 'DLV') {
            $productions = Production::with(['vendor', 'receivings.details'])
                ->whereBetween('order_date', [$from, $to])
                ->orderBy('order_date', 'desc')
                ->get();

            $deliveryReport = $productions->map(function ($prod) {
                $firstRec  = $prod->receivings->sortBy('rec_date')->first();
                $lastRec   = $prod->receivings->sortByDesc('rec_date')->first();
                $orderDate = Carbon::parse($prod->order_date);
                $firstDate = $firstRec ? Carbon::parse($firstRec->rec_date) : null;
                $lastDate  = $lastRec  ? Carbon::parse($lastRec->rec_date)  : null;

                return (object)[
                    'production_id'   => $prod->id,
                    'vendor'          => $prod->vendor->name ?? '-',
                    'order_date'      => $prod->order_date,
                    'first_receiving' => $firstDate?->toDateString() ?? 'Pending',
                    'last_receiving'  => $lastDate?->toDateString()  ?? 'Pending',
                    'days_to_first'   => $firstDate ? $orderDate->diffInDays($firstDate) : null,
                    'days_to_last'    => $lastDate  ? $orderDate->diffInDays($lastDate)  : null,
                    'total_raw'       => $prod->total_raw_given,
                    'total_fg'        => $prod->total_finished_received,
                    'wastage'         => $prod->total_wastage_returned,
                    'extra_returned'  => $prod->total_extra_raw_returned,
                    'receiving_count' => $prod->receivings->count(),
                    'status'          => $prod->receivings->count() === 0
                        ? 'Pending'
                        : ($prod->total_finished_received > 0 ? 'Received' : 'Partial'),
                ];
            });
        }

        // ── 8. Vendor Summary (SUM) ───────────────────────────────────
        if ($tab === 'SUM') {
            $productions = Production::with([
                    'vendor', 'details.product',
                    'receivings.details.product',
                    'wastageReceivings.details',
                ])
                ->whereBetween('order_date', [$from, $to])
                ->get();

            $orderSummary = $productions->groupBy('vendor_id')->map(function ($prods) {
                $vendor       = $prods->first()->vendor->name ?? '-';
                $totalRaw     = 0; $totalRawCost = 0; $totalFG      = 0;
                $totalMfgCost = 0; $totalExpCon  = 0;
                $totalWastage = 0; $totalExtra   = 0;

                foreach ($prods as $prod) {
                    $totalRaw     += $prod->total_raw_given;
                    $totalRawCost += $prod->total_raw_cost;
                    $totalFG      += $prod->total_finished_received;
                    $totalMfgCost += (float) $prod->receivings->flatMap->details->sum(
                        fn($d) => $d->received_qty * $d->manufacturing_cost
                    );
                    $totalExpCon  += $prod->expected_consumed;
                    $totalWastage += $prod->total_wastage_returned;      // write-off
                    $totalExtra   += $prod->total_extra_raw_returned;    // back to stock
                }

                $avgConActual   = $totalFG > 0 ? round($totalRaw / $totalFG, 4) : 0;
                $avgConExpected = $totalFG > 0 ? round($totalExpCon / $totalFG, 4) : 0;
                $avgRawCostPc   = $totalFG > 0 ? round($totalRawCost / $totalFG, 2) : 0;
                $avgMfgCostPc   = $totalFG > 0 ? round($totalMfgCost / $totalFG, 2) : 0;

                $variancePct = $avgConExpected > 0
                    ? round(($avgConActual - $avgConExpected) / $avgConExpected * 100, 1)
                    : null;

                return (object)[
                    'vendor'            => $vendor,
                    'orders'            => $prods->count(),
                    'total_raw'         => $totalRaw,
                    'raw_cost'          => $totalRawCost,
                    'total_fg'          => $totalFG,
                    'mfg_cost'          => $totalMfgCost,
                    'total_wastage'     => $totalWastage,
                    'total_extra'       => $totalExtra,
                    'avg_con_actual'    => $avgConActual,
                    'avg_con_expected'  => $avgConExpected,
                    'avg_raw_cost_pc'   => $avgRawCostPc,
                    'avg_mfg_cost_pc'   => $avgMfgCostPc,
                    'avg_total_cost_pc' => $avgRawCostPc + $avgMfgCostPc,
                    'variance_pct'      => $variancePct,
                    'variance_alert'    => $variancePct !== null && abs($variancePct) > 10,
                    'variance_critical' => $variancePct !== null && abs($variancePct) > 25,
                ];
            })->values();
        }

        return view('reports.production_reports', compact(
            'tab', 'from', 'to',
            'rawIssued', 'produced', 'costings',
            'vendorRawBalance', 'returnReport', 'wastageReport',
            'deliveryReport', 'orderSummary'
        ));
    }
}