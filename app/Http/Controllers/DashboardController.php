<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Production;
use App\Models\ProductionReceiving;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;
use App\Models\StockLedger;
use App\Models\Voucher;
use App\Services\Inventory;
use App\Support\EntryDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard — each block is shown (and calculated) only for users who have access
 * to that module. The super admin sees everything.
 */
class DashboardController extends Controller
{
    public function index()
    {
        $user  = auth()->user();
        $super = EntryDate::isSuperAdmin();
        $has   = fn (string ...$perms) => $super || collect($perms)->contains(fn ($p) => $user->can($p));

        $can = [
            'sales'      => $has('sale_invoices.index', 'reports.sales'),
            'purchases'  => $has('purchase_invoices.index', 'reports.purchase'),
            'accounts'   => $has('reports.accounts'),
            'stock'      => $has('reports.inventory', 'reports.location_stock'),
            'pdc'        => $has('pdc_cheques.index'),
            'receiving'  => $has('production_receiving.index'),
            'production' => $has('production.index'),
            'products'   => $has('products.index', 'reports.inventory'),
        ];
        $can['laam_sales'] = $can['sales'];
        $can['laam_stock'] = $has('reports.inventory', 'reports.location_stock', 'stock_transfer.index');
        $can['money']      = $can['accounts'];   // amounts in PKR on receiving / stock widgets
        $can['any']        = in_array(true, $can, true);

        $today      = Carbon::today()->toDateString();
        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $monthEnd   = Carbon::now()->endOfMonth()->toDateString();
        $data       = ['can' => $can];

        // ── Sales ─────────────────────────────────────────────────────
        if ($can['sales']) {
            $agg = fn ($q) => $q->whereNull('deleted_at')->selectRaw('COUNT(*) as count, SUM(net_amount) as total, SUM(paid_amount) as collected')->first();
            $data['todaySales']   = $agg(SaleInvoice::whereDate('date', $today));
            $data['monthlySales'] = $agg(SaleInvoice::whereBetween('date', [$monthStart, $monthEnd]));
            $data['recentSales']  = SaleInvoice::with('account')->whereNull('deleted_at')->latest()->take(8)->get();
        }

        // ── Receivables / payables / cash & bank ─────────────────────
        if ($can['accounts']) {
            $partyBalance = function (string $type): float {
                $accounts = ChartOfAccounts::where('account_type', $type)->get(['id', 'receivables', 'payables']);
                $ids      = $accounts->pluck('id');
                $opening  = $accounts->sum(fn ($a) => (float) $a->receivables - (float) $a->payables);
                $dr = (float) Voucher::whereIn('ac_dr_sid', $ids)->sum('amount');
                $cr = (float) Voucher::whereIn('ac_cr_sid', $ids)->sum('amount');
                return $opening + $dr - $cr;   // + = they owe us
            };
            $data['totalReceivables'] = max(0, $partyBalance('customer'));
            $data['totalPayables']    = max(0, -$partyBalance('vendor'));
            $data['cashBankAccounts'] = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->get()
                ->map(function ($acc) {
                    $dr = Voucher::where('ac_dr_sid', $acc->id)->whereNull('deleted_at')->sum('amount');
                    $cr = Voucher::where('ac_cr_sid', $acc->id)->whereNull('deleted_at')->sum('amount');
                    $acc->balance = $dr - $cr;
                    return $acc;
                });
        }

        // ── Stock value ───────────────────────────────────────────────
        $cost = null;
        if ($can['stock'] && $can['money']) {
            $cost = Inventory::avgCostResolver();
            $data['stockValue'] = StockLedger::groupBy('product_id', 'variation_id')
                ->selectRaw('product_id, variation_id, SUM(qty) AS qty')->get()
                ->sum(fn ($r) => (float) $r->qty * $cost($r->product_id, $r->variation_id));
        }

        // ── PDC ───────────────────────────────────────────────────────
        if ($can['pdc']) {
            $pdcDue = \App\Models\PdcCheque::whereIn('status', ['issued', 'presented'])
                ->where('cheque_date', '<=', Carbon::today()->addDays(7)->toDateString());
            $data['pdcDueAmount'] = (float) (clone $pdcDue)->sum('amount');
            $data['pdcDueCount']  = (clone $pdcDue)->count();
        }

        // ── Production orders (module kept for later use) ─────────────
        if ($can['production']) {
            $data['pendingProductions'] = Production::with(['vendor', 'details'])
                ->whereNull('deleted_at')->whereDoesntHave('receivings')
                ->orderBy('order_date', 'desc')->take(10)->get()
                ->map(fn ($p) => [
                    'id'       => $p->id,
                    'date'     => $p->order_date,
                    'vendor'   => $p->vendor->name ?? '-',
                    'type'     => ucfirst(str_replace('_', ' ', $p->production_type)),
                    'raw_qty'  => $p->details->sum('qty'),
                    'days_ago' => Carbon::parse($p->order_date)->diffInDays(Carbon::today()),
                ]);
            $data['pendingCount'] = Production::whereNull('deleted_at')->whereDoesntHave('receivings')->count();
        }

        // ── Today's receiving from CMT ────────────────────────────────
        if ($can['receiving']) {
            $todayReceivings = ProductionReceiving::with(['vendor', 'details.product'])
                ->whereDate('rec_date', $today)->whereNull('deleted_at')->get();
            $data['todayReceivedPcs']   = $todayReceivings->flatMap->details->sum('received_qty');
            $data['todayReceivedValue'] = $todayReceivings->flatMap->details->sum(fn ($d) => $d->manufacturing_cost * $d->received_qty);
            $data['todayReceivingList'] = $todayReceivings->map(fn ($r) => [
                'grn_no' => $r->grn_no,
                'vendor' => $r->vendor->name ?? '-',
                'items'  => $r->details->count(),
                'qty'    => $r->details->sum('received_qty'),
                'value'  => $r->details->sum(fn ($d) => $d->manufacturing_cost * $d->received_qty),
            ]);
        }

        // ── Low stock (all locations) ─────────────────────────────────
        if ($can['products']) {
            $qty = StockLedger::groupBy('product_id')->selectRaw('product_id, SUM(qty) AS qty')->pluck('qty', 'product_id');
            $data['lowStockProducts'] = Product::with(['category', 'measurementUnit'])
                ->where('is_active', true)->where('reorder_level', '>', 0)->get()
                ->each(fn ($p) => $p->current_stock = (float) ($qty[$p->id] ?? 0))
                ->filter(fn ($p) => $p->current_stock <= $p->reorder_level)
                ->take(10)->values();
        }

        // ── Sales vs purchases chart (last 6 months) ──────────────────
        if ($can['sales'] || $can['purchases']) {
            $chart = collect();
            for ($i = 5; $i >= 0; $i--) {
                $month = Carbon::now()->subMonths($i);
                $start = $month->copy()->startOfMonth()->toDateString();
                $end   = $month->copy()->endOfMonth()->toDateString();
                $chart->push([
                    'month'     => $month->format('M Y'),
                    'sales'     => $can['sales'] ? round(SaleInvoice::whereBetween('date', [$start, $end])->whereNull('deleted_at')->sum('net_amount'), 0) : null,
                    'purchases' => $can['purchases'] ? round(PurchaseInvoice::whereBetween('invoice_date', [$start, $end])->whereNull('deleted_at')
                        ->join('purchase_invoice_items', 'purchase_invoices.id', '=', 'purchase_invoice_items.purchase_invoice_id')
                        ->sum(DB::raw('purchase_invoice_items.quantity * purchase_invoice_items.price')), 0) : null,
                ]);
            }
            $data['chartData'] = $chart;
        }

        // ── LAAM: best sellers + their stock at LAAM ──────────────────
        if ($can['laam_sales'] || $can['laam_stock']) {
            $data['laam'] = $this->laam($can, $cost);
        }

        return view('home', $data);
    }

    /**
     * LAAM = customer account(s) whose name contains "LAAM". Best sellers are the
     * sizes sold to LAAM in the chosen period; stock is what sits at LAAM's location.
     */
    private function laam(array $can, ?callable $cost): array
    {
        $days     = in_array((int) request('laam_days'), [7, 30, 90, 365], true) ? (int) request('laam_days') : 30;
        $accounts = ChartOfAccounts::where('account_type', 'customer')->where('name', 'like', '%laam%')->get(['id', 'name']);
        $out      = ['days' => $days, 'accounts' => $accounts, 'rows' => collect(), 'stockPcs' => 0, 'stockValue' => null, 'skuCount' => 0];
        if ($accounts->isEmpty()) return $out;

        $locIds = Location::whereIn('chart_of_account_id', $accounts->pluck('id'))->pluck('id');

        // stock at LAAM per size
        $stock = $locIds->isEmpty() ? collect() : StockLedger::whereIn('location_id', $locIds)
            ->groupBy('product_id', 'variation_id')
            ->selectRaw('product_id, variation_id, SUM(qty) AS qty')->get()
            ->keyBy(fn ($r) => $r->product_id . '-' . ($r->variation_id ?? 0));

        if ($can['laam_stock']) {
            $inStock = $stock->filter(fn ($r) => (float) $r->qty > 0);
            $out['stockPcs'] = (float) $inStock->sum('qty');
            $out['skuCount'] = $inStock->count();
            if ($can['money']) {
                $cost ??= Inventory::avgCostResolver();
                $out['stockValue'] = $inStock->sum(fn ($r) => (float) $r->qty * $cost($r->product_id, $r->variation_id));
            }
        }

        if ($can['laam_sales']) {
            $from = Carbon::today()->subDays($days - 1)->toDateString();
            $sold = DB::table('sale_invoice_items as i')
                ->join('sale_invoices as s', 's.id', '=', 'i.sale_invoice_id')
                ->whereNull('s.deleted_at')
                ->whereIn('s.account_id', $accounts->pluck('id'))
                ->where('s.date', '>=', $from)
                ->groupBy('i.product_id', 'i.variation_id')
                ->selectRaw('i.product_id, i.variation_id, SUM(i.quantity) AS qty, SUM(i.quantity * i.sale_price) AS amount')
                ->orderByDesc('qty')->limit(15)->get();

            $products   = Product::whereIn('id', $sold->pluck('product_id'))->get(['id', 'name', 'sku'])->keyBy('id');
            $variations = ProductVariation::withTrashed()->whereIn('id', $sold->pluck('variation_id')->filter())->get(['id', 'sku'])->keyBy('id');

            $out['rows'] = $sold->map(function ($r) use ($products, $variations, $stock, $days, $can) {
                $atLaam   = (float) ($stock[$r->product_id . '-' . ($r->variation_id ?? 0)]->qty ?? 0);
                $perDay   = (float) $r->qty / $days;
                return [
                    'name'      => $products[$r->product_id]->name ?? 'Deleted item',
                    'sku'       => $r->variation_id ? ($variations[$r->variation_id]->sku ?? '') : ($products[$r->product_id]->sku ?? ''),
                    'sold'      => (float) $r->qty,
                    'amount'    => $can['money'] ? (float) $r->amount : null,
                    'stock'     => $can['laam_stock'] ? $atLaam : null,
                    'days_left' => $can['laam_stock'] && $perDay > 0 ? (int) floor(max(0, $atLaam) / $perDay) : null,
                ];
            });
        }

        return $out;
    }
}
