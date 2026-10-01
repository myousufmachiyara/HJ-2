<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\Location;
use App\Models\SaleInvoice;
use App\Models\SaleInvoiceItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Sales reports.
 *
 *  SR   Sales Register   — invoice-wise: dispatch location, qty, net, cost (COGS), gross profit, received, balance
 *  SRET Sales Returns    — return-wise: location, qty, value, refund, cost reversed
 *  CW   Customer Wise    — sales − returns = net sales, received, profit per customer / marketplace
 *  IW   Item Wise        — qty / value / cost / profit per variation, net of returns
 *  LW   Location Wise    — sales by dispatch location (own warehouse vs each marketplace)
 *
 * Cost = unit_cost frozen on each line when it was saved (weighted average).
 */
class SalesReportController extends Controller
{
    public function saleReports(Request $request)
    {
        $tab        = $request->get('tab', 'SR');
        $from       = $request->get('from_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to         = $request->get('to_date', Carbon::now()->format('Y-m-d'));
        $customerId = $request->get('customer_id');
        $locationId = $request->get('location_id');

        $invoiceScope = fn ($q) => $q->whereBetween('date', [$from, $to])
            ->when($customerId, fn ($w) => $w->where('account_id', $customerId))
            ->when($locationId, fn ($w) => $w->where('location_id', $locationId));

        $returnScope = fn ($q) => $q->whereBetween('return_date', [$from, $to])
            ->when($customerId, fn ($w) => $w->where('account_id', $customerId))
            ->when($locationId, fn ($w) => $w->where('location_id', $locationId));

        $rows = collect();

        if ($tab === 'SR') {
            $rows = SaleInvoice::with(['account', 'location', 'items'])
                ->where($invoiceScope)->orderBy('date')->orderBy('id')->get()
                ->map(function ($s) {
                    $cost = $s->items->sum(fn ($i) => (float) $i->unit_cost * (float) $i->quantity);
                    $net  = (float) $s->net_amount;
                    return (object) [
                        'date'     => $s->date,
                        'invoice'  => $s->invoice_no,
                        'id'       => $s->id,
                        'customer' => $s->account->name ?? 'Walk-in',
                        'location' => $s->location->name ?? '-',
                        'qty'      => (float) $s->items->sum('quantity'),
                        'total'    => $net,
                        'cost'     => round($cost, 2),
                        // profit on goods: net sale value excluding conveyance charged
                        'profit'   => round($net - (float) $s->convance_charges - $cost, 2),
                        'paid'     => (float) $s->paid_amount,
                        'balance'  => (float) $s->balance,
                        'status'   => $s->payment_status,
                    ];
                });
        }

        if ($tab === 'SRET') {
            $rows = SaleReturn::with(['customer', 'location', 'items'])
                ->where($returnScope)->orderBy('return_date')->orderBy('id')->get()
                ->map(fn ($r) => (object) [
                    'date'     => $r->return_date,
                    'invoice'  => 'SR-' . $r->id,
                    'ref'      => $r->sale_invoice_no,
                    'customer' => $r->customer->name ?? '-',
                    'location' => $r->location->name ?? '-',
                    'qty'      => (float) $r->items->sum('qty'),
                    'total'    => round($r->items->sum(fn ($i) => (float) $i->qty * (float) $i->price), 2),
                    'refund'   => (float) $r->refund_amount,
                    'cost'     => round($r->items->sum(fn ($i) => (float) $i->qty * (float) $i->unit_cost), 2),
                ]);
        }

        if ($tab === 'CW' || $tab === 'LW') {
            $key = $tab === 'CW' ? 'account_id' : 'location_id';

            $sales = SaleInvoice::with(['account', 'location', 'items'])->where($invoiceScope)->get()->groupBy($key);
            $rets  = SaleReturn::with(['customer', 'location', 'items'])->where($returnScope)->get()->groupBy($key);

            $rows = $sales->keys()->merge($rets->keys())->unique()->map(function ($k) use ($sales, $rets, $tab) {
                $s = $sales->get($k, collect());
                $r = $rets->get($k, collect());

                $salesAmt  = (float) $s->sum('net_amount');
                $salesCost = $s->sum(fn ($inv) => $inv->items->sum(fn ($i) => (float) $i->unit_cost * (float) $i->quantity));
                $retAmt    = $r->sum(fn ($ret) => $ret->items->sum(fn ($i) => (float) $i->qty * (float) $i->price));
                $retCost   = $r->sum(fn ($ret) => $ret->items->sum(fn ($i) => (float) $i->qty * (float) $i->unit_cost));
                $conv      = (float) $s->sum('convance_charges');

                $name = $tab === 'CW'
                    ? ($s->first()?->account->name ?? $r->first()?->customer->name ?? 'Walk-in')
                    : ($s->first()?->location->name ?? $r->first()?->location->name ?? 'Not set');

                return (object) [
                    'name'      => $name,
                    'count'     => $s->count(),
                    'qty'       => (float) $s->sum(fn ($inv) => $inv->items->sum('quantity')) - (float) $r->sum(fn ($ret) => $ret->items->sum('qty')),
                    'sales'     => round($salesAmt, 2),
                    'returns'   => round($retAmt, 2),
                    'net'       => round($salesAmt - $retAmt, 2),
                    'received'  => round((float) $s->sum('paid_amount'), 2),
                    'cost'      => round($salesCost - $retCost, 2),
                    'profit'    => round(($salesAmt - $conv - $retAmt) - ($salesCost - $retCost), 2),
                ];
            })->sortByDesc('net')->values();
        }

        if ($tab === 'IW') {
            $sold = SaleInvoiceItem::with(['product:id,name', 'variation:id,sku'])
                ->whereHas('invoice', $invoiceScope)->get()
                ->groupBy(fn ($i) => $i->product_id . '-' . ($i->variation_id ?? 0));
            $ret = SaleReturnItem::whereHas('saleReturn', $returnScope)->get()
                ->groupBy(fn ($i) => $i->product_id . '-' . ($i->variation_id ?? 0));

            $rows = $sold->map(function ($lines, $k) use ($ret) {
                $first = $lines->first();
                $r     = $ret->get($k, collect());
                $value = $lines->sum(fn ($i) => $i->getLineTotal());
                $cost  = $lines->sum(fn ($i) => (float) $i->unit_cost * (float) $i->quantity);
                $rQty  = (float) $r->sum('qty');
                $rVal  = $r->sum(fn ($i) => (float) $i->qty * (float) $i->price);
                $rCost = $r->sum(fn ($i) => (float) $i->qty * (float) $i->unit_cost);
                return (object) [
                    'product'   => $first->product->name ?? $first->item_name ?? '-',
                    'variation' => $first->variation->sku ?? null,
                    'qty'       => (float) $lines->sum('quantity'),
                    'ret_qty'   => $rQty,
                    'net_qty'   => (float) $lines->sum('quantity') - $rQty,
                    'net'       => round($value - $rVal, 2),
                    'cost'      => round($cost - $rCost, 2),
                    'profit'    => round(($value - $rVal) - ($cost - $rCost), 2),
                ];
            })->sortByDesc('net_qty')->values();
        }

        return view('reports.sales_reports', [
            'tab'        => $tab,
            'from'       => $from,
            'to'         => $to,
            'rows'       => $rows,
            'customers'  => ChartOfAccounts::where('account_type', 'customer')->orderBy('name')->get(),
            'locations'  => Location::whereIn('type', [Location::WAREHOUSE, Location::CUSTOMER])->orderBy('type')->orderBy('name')->get(),
            'customerId' => $customerId,
            'locationId' => $locationId,
        ]);
    }
}
