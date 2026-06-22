<?php

namespace App\Http\Controllers;

use App\Models\SaleInvoice;
use App\Models\SalePayment;
use App\Models\Production;
use App\Models\ProductionReceiving;
use App\Models\PurchaseInvoice;
use App\Models\Product;
use App\Models\Voucher;
use App\Models\ChartOfAccounts;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $today     = Carbon::today()->toDateString();
        $monthStart= Carbon::now()->startOfMonth()->toDateString();
        $monthEnd  = Carbon::now()->endOfMonth()->toDateString();

        // ── Today's Sales ─────────────────────────────────────────────
        $todaySales = SaleInvoice::whereDate('date', $today)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as count, SUM(net_amount) as total, SUM(paid_amount) as collected')
            ->first();

        // ── Monthly Sales ─────────────────────────────────────────────
        $monthlySales = SaleInvoice::whereBetween('date', [$monthStart, $monthEnd])
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as count, SUM(net_amount) as total, SUM(paid_amount) as collected')
            ->first();

        // ── Receivables (outstanding balance) ─────────────────────────
        $totalReceivables = SaleInvoice::whereNull('deleted_at')
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->sum('balance');

        // ── Payables (vendor outstanding) ─────────────────────────────
        $vendorIds = ChartOfAccounts::where('account_type', 'vendor')->pluck('id');
        $totalPayables = Voucher::whereIn('ac_cr_sid', $vendorIds)
            ->whereNull('deleted_at')
            ->sum('amount')
            - Voucher::whereIn('ac_dr_sid', $vendorIds)
            ->whereNull('deleted_at')
            ->sum('amount');

        // ── Production Orders ─────────────────────────────────────────
        // Pending = has no receiving at all
        $pendingProductions = Production::with(['vendor', 'details'])
            ->whereNull('deleted_at')
            ->whereDoesntHave('receivings')
            ->orderBy('order_date', 'desc')
            ->take(10)
            ->get()
            ->map(function ($p) {
                return [
                    'id'       => $p->id,
                    'date'     => $p->order_date,
                    'vendor'   => $p->vendor->name ?? '-',
                    'type'     => ucfirst(str_replace('_', ' ', $p->production_type)),
                    'raw_qty'  => $p->details->sum('qty'),
                    'days_ago' => Carbon::parse($p->order_date)->diffInDays(Carbon::today()),
                ];
            });

        $pendingCount = Production::whereNull('deleted_at')
            ->whereDoesntHave('receivings')
            ->count();

        // Partial = has some receiving but raw still at vendor
        $inProcessCount = Production::whereNull('deleted_at')
            ->whereHas('receivings')
            ->count();

        // ── Today's Production Received ───────────────────────────────
        $todayReceivings = ProductionReceiving::with(['vendor', 'details.product'])
            ->whereDate('rec_date', $today)
            ->whereNull('deleted_at')
            ->get();

        $todayReceivedPcs   = $todayReceivings->flatMap->details->sum('received_qty');
        $todayReceivedValue = $todayReceivings->flatMap->details
            ->sum(fn($d) => $d->manufacturing_cost * $d->received_qty);

        $todayReceivingList = $todayReceivings->map(function ($r) {
            return [
                'grn_no'   => $r->grn_no,
                'vendor'   => $r->vendor->name ?? '-',
                'items'    => $r->details->count(),
                'qty'      => $r->details->sum('received_qty'),
                'value'    => $r->details->sum(fn($d) => $d->manufacturing_cost * $d->received_qty),
            ];
        });

        // ── Stock Under Minimum ───────────────────────────────────────
        // Calculate current stock from purchase invoices + production receiving - sales
        $products = Product::with(['category', 'measurementUnit'])
            ->where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->whereNull('deleted_at')
            ->get();

        $lowStockProducts = $products->filter(function ($p) {
            $purchased = DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoices.id', '=', 'purchase_invoice_items.purchase_invoice_id')
                ->where('purchase_invoice_items.item_id', $p->id)
                ->whereNull('purchase_invoices.deleted_at')
                ->sum('purchase_invoice_items.quantity');

            $received = DB::table('production_receiving_details')
                ->join('production_receivings', 'production_receivings.id', '=', 'production_receiving_details.production_receiving_id')
                ->where('production_receiving_details.product_id', $p->id)
                ->whereNull('production_receivings.deleted_at')
                ->sum('production_receiving_details.received_qty');

            $sold = DB::table('sale_invoice_items')
                ->join('sale_invoices', 'sale_invoices.id', '=', 'sale_invoice_items.sale_invoice_id')
                ->where('sale_invoice_items.product_id', $p->id)
                ->whereNull('sale_invoices.deleted_at')
                ->sum('sale_invoice_items.quantity');

            $currentStock = (float)$p->opening_stock + $purchased + $received - $sold;
            $p->current_stock = $currentStock;

            return $currentStock <= $p->reorder_level;
        })->take(10)->values();

        // ── Cash & Bank Positions ─────────────────────────────────────
        $cashBankAccounts = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])
            ->get()
            ->map(function ($acc) {
                $dr = Voucher::where('ac_dr_sid', $acc->id)->whereNull('deleted_at')->sum('amount');
                $cr = Voucher::where('ac_cr_sid', $acc->id)->whereNull('deleted_at')->sum('amount');
                $acc->balance = $dr - $cr;
                return $acc;
            });

        // ── Recent Sale Invoices ──────────────────────────────────────
        $recentSales = SaleInvoice::with('account')
            ->whereNull('deleted_at')
            ->latest()
            ->take(8)
            ->get();

        // ── Monthly Chart Data (last 6 months) ────────────────────────
        $chartData = collect();
        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $start = $month->copy()->startOfMonth()->toDateString();
            $end   = $month->copy()->endOfMonth()->toDateString();

            $sales = SaleInvoice::whereBetween('date', [$start, $end])
                ->whereNull('deleted_at')
                ->sum('net_amount');

            $purchases = PurchaseInvoice::whereBetween('invoice_date', [$start, $end])
                ->whereNull('deleted_at')
                ->join('purchase_invoice_items', 'purchase_invoices.id', '=', 'purchase_invoice_items.purchase_invoice_id')
                ->sum(DB::raw('purchase_invoice_items.quantity * purchase_invoice_items.price'));

            $chartData->push([
                'month'    => $month->format('M Y'),
                'sales'    => round($sales, 0),
                'purchases'=> round($purchases, 0),
            ]);
        }

        return view('home', compact(
            'todaySales',
            'monthlySales',
            'totalReceivables',
            'totalPayables',
            'pendingProductions',
            'pendingCount',
            'inProcessCount',
            'todayReceivedPcs',
            'todayReceivedValue',
            'todayReceivingList',
            'lowStockProducts',
            'cashBankAccounts',
            'recentSales',
            'chartData'
        ));
    }
}