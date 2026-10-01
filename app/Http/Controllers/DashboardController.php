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

        // ── Receivables / Payables — from the ledger incl. opening balances ──
        $partyBalance = function (string $type): float {
            $accounts = ChartOfAccounts::where('account_type', $type)->get(['id', 'receivables', 'payables']);
            $ids      = $accounts->pluck('id');
            $opening  = $accounts->sum(fn ($a) => (float) $a->receivables - (float) $a->payables);
            $dr = (float) Voucher::whereIn('ac_dr_sid', $ids)->sum('amount');
            $cr = (float) Voucher::whereIn('ac_cr_sid', $ids)->sum('amount');
            return $opening + $dr - $cr;   // + = they owe us
        };
        $totalReceivables = max(0, $partyBalance('customer'));
        $totalPayables    = max(0, -$partyBalance('vendor'));

        // ── Stock value (all locations) & PDC due ─────────────────────
        $cost = \App\Services\Inventory::avgCostResolver();
        $stockValue = \App\Models\StockLedger::groupBy('product_id', 'variation_id')
            ->selectRaw('product_id, variation_id, SUM(qty) AS qty')->get()
            ->sum(fn ($r) => (float) $r->qty * $cost($r->product_id, $r->variation_id));
        $pdcDue = \App\Models\PdcCheque::whereIn('status', ['issued', 'presented'])
            ->where('cheque_date', '<=', Carbon::today()->addDays(7)->toDateString());
        $pdcDueAmount = (float) (clone $pdcDue)->sum('amount');
        $pdcDueCount  = (clone $pdcDue)->count();

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

        // ── Stock Under Minimum (stock ledger, all locations) ─────────
        $qty = \App\Models\StockLedger::groupBy('product_id')->selectRaw('product_id, SUM(qty) AS qty')->pluck('qty', 'product_id');
        $lowStockProducts = Product::with(['category', 'measurementUnit'])
            ->where('is_active', true)
            ->where('reorder_level', '>', 0)
            ->get()
            ->each(fn ($p) => $p->current_stock = (float) ($qty[$p->id] ?? 0))
            ->filter(fn ($p) => $p->current_stock <= $p->reorder_level)
            ->take(10)->values();

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
            'stockValue',
            'pdcDueAmount',
            'pdcDueCount',
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