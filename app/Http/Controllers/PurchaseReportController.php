<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\Location;
use App\Models\PdcCheque;
use App\Models\ProductionReceiving;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Purchase reports.
 *
 *  PUR  Purchase Register      — line-wise, with drop-off location
 *  PR   Purchase Returns
 *  GRN  CMT / FG Receiving     — pieces received per GRN, CMT bill, fabric consumed at the CMT
 *  VWP  Vendor Summary         — purchases + CMT bills − returns, PDC issued / cleared, ledger balance
 *  PDC  Cheques                — post-dated cheques issued in the period (by cheque date)
 *  CMT  CMT Fabric Summary     — per CMT vendor × fabric × panna: given, consumed by received
 *                                articles, expected remaining, and how many more pieces it could make
 */
class PurchaseReportController extends Controller
{
    public function purchaseReports(Request $request)
    {
        $tab      = $request->get('tab', 'PUR');
        $from     = $request->get('from_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to       = $request->get('to_date', Carbon::now()->format('Y-m-d'));
        $vendorId = $request->get('vendor_id');
        $locId    = $request->get('location_id');

        $vendors   = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $locations = Location::whereIn('type', [Location::WAREHOUSE, Location::VENDOR])->orderBy('type')->orderBy('name')->get();
        $rows      = collect();

        if ($tab === 'PUR') {
            $rows = PurchaseInvoice::with(['vendor', 'dropoffLocation', 'items.product', 'items.variation', 'items.measurementUnit'])
                ->whereBetween('invoice_date', [$from, $to])
                ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
                ->when($locId, fn ($q) => $q->where('dropoff_location_id', $locId))
                ->orderBy('invoice_date')->get()
                ->flatMap(fn ($inv) => $inv->items->map(fn ($item) => (object) [
                    'date'        => $inv->invoice_date,
                    'id'          => $inv->id,
                    'invoice_no'  => $inv->invoice_no,
                    'bill_no'     => $inv->bill_no,
                    'vendor_name' => $inv->vendor->name ?? '-',
                    'dropoff'     => $inv->dropoffLocation->name ?? '-',
                    'item_name'   => $item->product->name ?? $item->item_name ?? '-',
                    'variation'   => $item->variation->sku ?? null,
                    'unit'        => $item->measurementUnit->shortcode ?? '-',
                    'quantity'    => (float) $item->quantity,
                    'rate'        => (float) $item->price,
                    'total'       => (float) $item->quantity * (float) $item->price,
                ]));
        }

        if ($tab === 'PR') {
            $rows = PurchaseReturn::with(['vendor', 'items.product', 'items.variation', 'items.measurementUnit'])
                ->whereBetween('return_date', [$from, $to])
                ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
                ->orderBy('return_date')->get()
                ->flatMap(fn ($ret) => $ret->items->map(fn ($item) => (object) [
                    'date'        => $ret->return_date,
                    'return_no'   => $ret->return_no ?? $ret->id,
                    'vendor_name' => $ret->vendor->name ?? '-',
                    'item_name'   => $item->product->name ?? $item->item_name ?? '-',
                    'variation'   => $item->variation->sku ?? null,
                    'unit'        => $item->measurementUnit->shortcode ?? '-',
                    'quantity'    => (float) $item->quantity,
                    'rate'        => (float) $item->price,
                    'total'       => (float) $item->quantity * (float) $item->price,
                ]));
        }

        if ($tab === 'GRN') {
            $rows = ProductionReceiving::with(['vendor', 'details.fabric:id,name', 'details.fabricVariation:id,sku'])
                ->whereBetween('rec_date', [$from, $to])
                ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
                ->orderBy('rec_date')->get()
                ->map(fn ($r) => (object) [
                    'date'         => $r->rec_date,
                    'id'           => $r->id,
                    'grn_no'       => $r->grn_no,
                    'vendor_name'  => $r->vendor->name ?? '-',
                    'pcs'          => (float) $r->details->sum('received_qty'),
                    'cmt_bill'     => round($r->details->sum(fn ($d) => $d->received_qty * $d->manufacturing_cost)
                                        + (float) $r->convance_charges - (float) $r->bill_discount, 2),
                    'fabric'       => $r->details->filter(fn ($d) => $d->fabric)
                                        ->map(fn ($d) => $d->fabric->name . ($d->fabricVariation ? ' ' . $d->fabricVariation->sku : ''))
                                        ->unique()->implode(', '),
                    'fabric_qty'   => round((float) $r->details->sum('fabric_qty'), 3),
                    'fabric_value' => round($r->details->sum(fn ($d) => $d->fabric_qty * $d->fabric_rate), 2),
                    'missing_cost' => $r->details->contains(fn ($d) => (float) $d->manufacturing_cost <= 0),
                ]);
        }

        if ($tab === 'VWP') {
            $list = $vendorId ? $vendors->where('id', $vendorId) : $vendors;
            $rows = $list->map(function ($v) use ($from, $to) {
                $purchases = PurchaseInvoice::with('items')->where('vendor_id', $v->id)->whereBetween('invoice_date', [$from, $to])->get()
                    ->sum(fn ($i) => $i->items->sum(fn ($x) => $x->quantity * $x->price) + $i->convance_charges + $i->labour_charges - $i->bill_discount);
                $cmt = ProductionReceiving::with('details')->where('vendor_id', $v->id)->whereBetween('rec_date', [$from, $to])->get()
                    ->sum(fn ($r) => $r->details->sum(fn ($d) => $d->received_qty * $d->manufacturing_cost) + $r->convance_charges - $r->bill_discount);
                $returns = PurchaseReturn::with('items')->where('vendor_id', $v->id)->whereBetween('return_date', [$from, $to])->get()
                    ->sum(fn ($r) => $r->items->sum(fn ($x) => $x->quantity * $x->price));
                $pdcIssued  = PdcCheque::where('vendor_id', $v->id)->whereIn('status', PdcCheque::LIVE)->whereBetween('issue_date', [$from, $to])->sum('amount');
                $pdcPending = PdcCheque::where('vendor_id', $v->id)->whereIn('status', ['issued', 'presented'])->sum('amount');

                // closing balance as of $to (opening + all vouchers), + = we owe the vendor
                $cr = (float) Voucher::where('ac_cr_sid', $v->id)->where('date', '<=', $to)->sum('amount');
                $dr = (float) Voucher::where('ac_dr_sid', $v->id)->where('date', '<=', $to)->sum('amount');
                $balance = (float) $v->payables - (float) $v->receivables + $cr - $dr;

                if (!$purchases && !$cmt && !$returns && !$pdcIssued && round($balance, 2) == 0.0) return null;

                return (object) [
                    'vendor'      => $v->name,
                    'vendor_type' => $v->vendor_type,
                    'purchases'   => round($purchases, 2),
                    'cmt'         => round($cmt, 2),
                    'returns'     => round($returns, 2),
                    'net'         => round($purchases + $cmt - $returns, 2),
                    'pdc_issued'  => round($pdcIssued, 2),
                    'pdc_pending' => round($pdcPending, 2),
                    'balance'     => round($balance, 2),
                ];
            })->filter()->sortByDesc('balance')->values();
        }

        if ($tab === 'CMT') {
            $rows = $this->cmtFabricSummary($from, $to, $vendorId);
        }

        if ($tab === 'PDC') {
            $rows = PdcCheque::with(['vendor', 'bankAccount', 'bills'])
                ->whereBetween('cheque_date', [$from, $to])
                ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
                ->orderBy('cheque_date')->get();
        }

        return view('reports.purchase_reports', compact('tab', 'from', 'to', 'vendors', 'locations', 'rows', 'vendorId', 'locId'));
    }

    /**
     * Fabric position at each CMT vendor (vendor stock locations), from the stock ledger.
     *   opening   = balance before $from
     *   given     = fabric that came to the vendor in the period (purchases dropped there, movements in, opening/adjustments +)
     *   consumed  = fabric used by FG received in the period (pieces × consumption)
     *   other out = fabric moved back / adjusted out
     *   remaining = what the vendor should still have on $to
     */
    private function cmtFabricSummary(string $from, string $to, ?string $vendorId)
    {
        $locs = Location::vendors()->with('party')
            ->when($vendorId, fn ($q) => $q->where('chart_of_account_id', $vendorId))
            ->get()->keyBy('id');
        if ($locs->isEmpty()) return collect();

        $receivingClass = ProductionReceiving::class;
        $ledger = \App\Models\StockLedger::whereIn('location_id', $locs->keys())
            ->where('date', '<=', $to)
            ->whereHas('product', fn ($q) => $q->where('item_type', 'raw'))
            ->groupBy('location_id', 'product_id', 'variation_id')
            ->selectRaw("location_id, product_id, variation_id,
                SUM(CASE WHEN date < ? THEN qty ELSE 0 END) AS opening,
                SUM(CASE WHEN date >= ? AND qty > 0 THEN qty ELSE 0 END) AS given,
                SUM(CASE WHEN date >= ? AND qty < 0 AND source_type = ? THEN -qty ELSE 0 END) AS consumed,
                SUM(CASE WHEN date >= ? AND qty < 0 AND source_type <> ? THEN -qty ELSE 0 END) AS other_out,
                SUM(qty) AS remaining", [$from, $from, $from, $receivingClass, $from, $receivingClass])
            ->get();

        $cost = \App\Services\Inventory::avgCostResolver($to);

        // pieces received per vendor × fabric × panna × article in the period
        $received = \App\Models\ProductionReceivingDetail::with(['receiving:id,vendor_id', 'product:id,name', 'variation:id,sku'])
            ->whereNotNull('fabric_id')
            ->whereHas('receiving', fn ($q) => $q->whereBetween('rec_date', [$from, $to])
                ->when($vendorId, fn ($w) => $w->where('vendor_id', $vendorId)))
            ->get()
            ->groupBy(fn ($d) => $d->receiving->vendor_id . '|' . $d->fabric_id . '|' . ($d->fabric_variation_id ?? 0));

        $mappings = \App\Models\FabricArticle::with(['article:id,name', 'articleVariation:id,sku'])->get()
            ->groupBy(fn ($m) => $m->fabric_id . '|' . ($m->fabric_variation_id ?? 0));

        $names = \App\Models\Product::whereIn('id', $ledger->pluck('product_id'))->pluck('name', 'id');
        $skus  = \App\Models\ProductVariation::whereIn('id', $ledger->pluck('variation_id')->filter())->pluck('sku', 'id');

        return $ledger->groupBy('location_id')->map(function ($lines, $locId) use ($locs, $received, $mappings, $cost, $names, $skus) {
            $loc = $locs[$locId];
            $fabrics = $lines->map(function ($l) use ($loc, $received, $mappings, $cost, $names, $skus) {
                $key       = $loc->chart_of_account_id . '|' . $l->product_id . '|' . ($l->variation_id ?? 0);
                $remaining = round((float) $l->remaining, 3);
                $articles  = ($received[$key] ?? collect())
                    ->groupBy(fn ($d) => $d->product_id . '-' . ($d->variation_id ?? 0))
                    ->map(fn ($g) => (object) [
                        'article' => ($g->first()->product->name ?? '-') . ($g->first()->variation ? ' (' . $g->first()->variation->sku . ')' : ''),
                        'pcs'     => (float) $g->sum('received_qty'),
                        'fabric'  => round((float) $g->sum('fabric_qty'), 3),
                    ])->values();
                $canMake = ($mappings[$l->product_id . '|' . ($l->variation_id ?? 0)] ?? collect())
                    ->map(fn ($m) => (object) [
                        'article'     => ($m->article->name ?? '-') . ' (' . ($m->articleVariation->sku ?? 'all sizes') . ')',
                        'consumption' => $m->consumption,
                        'pcs'         => $remaining > 0 && $m->consumption > 0 ? floor($remaining / $m->consumption) : 0,
                    ])->values();

                return (object) [
                    'fabric'    => $names[$l->product_id] ?? ('#' . $l->product_id),
                    'panna'     => $l->variation_id ? ($skus[$l->variation_id] ?? '#' . $l->variation_id) : null,
                    'opening'   => round((float) $l->opening, 3),
                    'given'     => round((float) $l->given, 3),
                    'consumed'  => round((float) $l->consumed, 3),
                    'other_out' => round((float) $l->other_out, 3),
                    'remaining' => $remaining,
                    'value'     => round($remaining * $cost($l->product_id, $l->variation_id), 2),
                    'articles'  => $articles,
                    'can_make'  => $canMake,
                ];
            })->filter(fn ($f) => $f->opening || $f->given || $f->consumed || $f->other_out || $f->remaining)->values();

            return (object) ['location' => $loc, 'vendor' => $loc->party->name ?? $loc->name, 'fabrics' => $fabrics];
        })->filter(fn ($v) => $v->fabrics->isNotEmpty())->sortBy('vendor')->values();
    }
}
