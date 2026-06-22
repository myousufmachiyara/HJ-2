<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\ChartOfAccounts;
use Carbon\Carbon;

class PurchaseReportController extends Controller
{
    public function purchaseReports(Request $request)
    {
        $tab  = $request->get('tab', 'PUR');
        $from = $request->get('from_date', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $to   = $request->get('to_date',   Carbon::now()->format('Y-m-d'));

        $vendors = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();

        $purchaseRegister   = collect();
        $purchaseReturns    = collect();
        $vendorWisePurchase = collect();

        // ── Purchase Register ─────────────────────────────────────────
        if ($tab === 'PUR') {
            $purchaseRegister = PurchaseInvoice::with(['vendor', 'items.product', 'items.variation', 'items.measurementUnit'])
                ->whereBetween('invoice_date', [$from, $to])
                ->when($request->filled('vendor_id'), fn($q) => $q->where('vendor_id', $request->vendor_id))
                ->get()
                ->flatMap(function ($invoice) {
                    return $invoice->items->map(fn($item) => (object)[
                        'date'        => $invoice->invoice_date,
                        'invoice_no'  => $invoice->bill_no ?? $invoice->id,
                        'vendor_name' => $invoice->vendor->name ?? '-',
                        'item_name'   => $item->product->name ?? $item->item_name ?? '-',
                        'variation'   => $item->variation->sku ?? '-',
                        'unit'        => $item->measurementUnit->shortcode ?? '-',
                        'quantity'    => $item->quantity,
                        'rate'        => $item->price,
                        'total'       => $item->quantity * $item->price,
                    ]);
                });
        }

        // ── Purchase Returns ──────────────────────────────────────────
        if ($tab === 'PR') {
            $purchaseReturns = PurchaseReturn::with(['vendor', 'items.product', 'items.variation', 'items.measurementUnit'])
                ->whereBetween('return_date', [$from, $to])
                ->when($request->filled('vendor_id'), fn($q) => $q->where('vendor_id', $request->vendor_id))
                ->get()
                ->flatMap(function ($return) {
                    return $return->items->map(fn($item) => (object)[
                        'date'        => $return->return_date,
                        'return_no'   => $return->id,
                        'vendor_name' => $return->vendor->name ?? '-',
                        'item_name'   => $item->product->name ?? $item->item_name ?? '-',
                        'variation'   => $item->variation->sku ?? '-',
                        'unit'        => $item->measurementUnit->shortcode ?? '-',
                        'quantity'    => $item->quantity,
                        'rate'        => $item->price,
                        'total'       => $item->quantity * $item->price,
                    ]);
                });
        }

        // ── Vendor-wise Purchases ─────────────────────────────────────
        if ($tab === 'VWP') {
            $vendorWisePurchase = PurchaseInvoice::with(['vendor', 'items.product', 'items.variation', 'items.measurementUnit'])
                ->whereBetween('invoice_date', [$from, $to])
                ->when($request->filled('vendor_id'), fn($q) => $q->where('vendor_id', $request->vendor_id))
                ->get()
                ->groupBy('vendor_id')
                ->map(function ($invoices) {
                    $vendor = $invoices->first()->vendor->name ?? 'Unknown Vendor';

                    $items = $invoices->flatMap(fn($inv) =>
                        $inv->items->map(fn($item) => (object)[
                            'invoice_date' => $inv->invoice_date,
                            'invoice_no'   => $inv->bill_no ?? $inv->id,
                            'item_name'    => $item->product->name ?? $item->item_name ?? '-',
                            'variation'    => $item->variation->sku ?? '-',
                            'unit'         => $item->measurementUnit->shortcode ?? '-',
                            'quantity'     => $item->quantity,
                            'rate'         => $item->price,
                            'total'        => $item->quantity * $item->price,
                        ])
                    );

                    return (object)[
                        'vendor_name'  => $vendor,
                        'items'        => $items,
                        'total_qty'    => $items->sum('quantity'),
                        'total_amount' => $items->sum('total'),
                    ];
                })->values();
        }

        return view('reports.purchase_reports', compact(
            'tab', 'from', 'to', 'vendors',
            'purchaseRegister', 'purchaseReturns', 'vendorWisePurchase'
        ));
    }
}