<?php

namespace App\Http\Controllers;

use App\Models\SaleInvoice;
use App\Models\SaleInvoiceItem;
use App\Models\SalePayment;
use App\Models\PosSession;
use App\Models\PosHeldOrder;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\MeasurementUnit;
use App\Models\ChartOfAccounts;
use App\Traits\PostsAccountingEntries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PosController extends Controller
{
    use PostsAccountingEntries;

    // ── POS Screen ────────────────────────────────────────────────────

    public function index()
    {
        $productsRaw = Product::with(['variations', 'measurementUnit', 'category'])
        ->where('is_active', true)
        ->where('item_type', 'fg')
        ->get();

        // Build the JS-safe array in PHP, not in Blade
        $productsJs = $productsRaw->map(function ($p) {
            return [
                'id'          => $p->id,
                'name'        => $p->name,
                'sku'         => $p->sku,
                'barcode'     => $p->barcode,
                'price'       => $p->selling_price,
                'unit_id'     => $p->measurement_unit,
                'unit'        => optional($p->measurementUnit)->shortcode ?? 'pcs',
                'category_id' => $p->category_id,
                'variations'  => $p->variations->map(function ($v) use ($p) {
                    return [
                        'id'    => $v->id,
                        'sku'   => $v->sku,
                        'price' => $p->selling_price,
                    ];
                })->values()->toArray(),
            ];
        })->values();

        $categories = ProductCategory::orderBy('name')->get();
        $customers  = ChartOfAccounts::where('account_type', 'customer')->orderBy('name')->get();
        $accounts   = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->orderBy('name')->get();
        $heldOrders = PosHeldOrder::where('user_id', auth()->id())->latest()->get();

        // Pass both: raw for the product grid, JS-safe for the script
        return view('pos.index', compact(
            'productsRaw',
            'productsJs',
            'categories',
            'customers',
            'accounts',
            'heldOrders'
        ));
    }

    // ── Checkout ──────────────────────────────────────────────────────

    public function checkout(Request $request)
    {
        $request->validate([
            'customer_id'        => 'nullable|exists:chart_of_accounts,id',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.unit_id'    => 'required|exists:measurement_units,id',
            'items.*.quantity'   => 'required|numeric|min:0.01',
            'items.*.price'      => 'required|numeric|min:0',
            'items.*.discount'   => 'nullable|numeric|min:0|max:100',
            'discount'           => 'nullable|numeric|min:0',
            'payment_account_id' => 'required|exists:chart_of_accounts,id',
            'payment_amount'     => 'required|numeric|min:0',
            'payment_type'       => 'required|in:cash,card,bank',
        ]);

        DB::beginTransaction();
        try {
            $invoiceNo  = 'POS-' . str_pad(SaleInvoice::withTrashed()->count() + 1, 5, '0', STR_PAD_LEFT);
            $subTotal   = 0;
            $billDisc   = (float)($request->discount ?? 0);

            foreach ($request->items as $item) {
                $price     = (float)$item['price'];
                $qty       = (float)$item['quantity'];
                $disc      = (float)($item['discount'] ?? 0);
                $subTotal += ($price - ($price * $disc / 100)) * $qty;
            }

            $netAmount = $subTotal - $billDisc;
            $paid      = min((float)$request->payment_amount, $netAmount);
            $balance   = max(0, $netAmount - $paid);
            $status    = $paid >= $netAmount ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');

            $invoice = SaleInvoice::create([
                'invoice_no'     => $invoiceNo,
                'date'           => now()->toDateString(),
                'account_id'     => $request->customer_id,
                'type'           => 'cash',
                'sub_total'      => $subTotal,
                'discount'       => $billDisc,
                'convance_charges' => 0,
                'net_amount'     => $netAmount,
                'paid_amount'    => $paid,
                'balance'        => $balance,
                'payment_status' => $status,
                'remarks'        => 'POS Sale',
                'created_by'     => auth()->id(),
            ]);

            $productNames = Product::pluck('name', 'id');
            foreach ($request->items as $item) {
                $invoice->items()->create([
                    'product_id'   => $item['product_id'],
                    'variation_id' => $item['variation_id'] ?? null,
                    'item_name'    => $productNames[$item['product_id']] ?? null,
                    'sale_price'   => $item['price'],
                    'discount'     => $item['discount'] ?? 0,
                    'quantity'     => $item['quantity'],
                    'unit'         => $item['unit_id'],
                ]);
            }

            if ($paid > 0) {
                SalePayment::create([
                    'sale_invoice_id' => $invoice->id,
                    'account_id'      => $request->payment_account_id,
                    'payment_date'    => now()->toDateString(),
                    'amount'          => $paid,
                    'reference'       => $request->payment_type,
                    'remarks'         => 'POS payment — ' . strtoupper($request->payment_type),
                    'created_by'      => auth()->id(),
                ]);
            }

            $invoice->load(['items', 'payments']);
            $this->postSaleEntries($invoice);

            // Delete held order if this was recalled
            if ($request->held_order_id) {
                PosHeldOrder::where('id', $request->held_order_id)
                    ->where('user_id', auth()->id())
                    ->delete();
            }

            DB::commit();

            return response()->json([
                'success'    => true,
                'invoice_id' => $invoice->id,
                'invoice_no' => $invoice->invoice_no,
                'net_amount' => $netAmount,
                'paid'       => $paid,
                'change'     => max(0, (float)$request->payment_amount - $netAmount),
                'balance'    => $balance,
                'status'     => $status,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[POS] Checkout failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ── Hold Order ────────────────────────────────────────────────────

    public function holdOrder(Request $request)
    {
        $request->validate([
            'cart'        => 'required|array|min:1',
            'total'       => 'required|numeric',
            'customer_id' => 'nullable|exists:chart_of_accounts,id',
            'label'       => 'nullable|string|max:100',
        ]);

        $held = PosHeldOrder::create([
            'user_id'     => auth()->id(),
            'customer_id' => $request->customer_id,
            'label'       => $request->label ?? ('Hold #' . (PosHeldOrder::where('user_id', auth()->id())->count() + 1)),
            'cart'        => json_encode($request->cart),
            'total'       => $request->total,
        ]);

        return response()->json(['success' => true, 'id' => $held->id, 'label' => $held->label]);
    }

    public function recallOrder($id)
    {
        $held = PosHeldOrder::where('user_id', auth()->id())->findOrFail($id);
        return response()->json([
            'success'     => true,
            'id'          => $held->id,
            'label'       => $held->label,
            'customer_id' => $held->customer_id,
            'cart'        => json_decode($held->cart, true),
            'total'       => $held->total,
        ]);
    }

    public function deleteHeldOrder($id)
    {
        PosHeldOrder::where('user_id', auth()->id())->findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // ── Z-Report ──────────────────────────────────────────────────────

    public function zReport()
    {
        $today = now()->toDateString();

        $invoices = SaleInvoice::with(['items', 'payments.account'])
            ->where('created_by', auth()->id())
            ->whereDate('date', $today)
            ->where('type', 'cash')
            ->get();

        $totalSales    = $invoices->sum('net_amount');
        $totalReceived = $invoices->sum('paid_amount');
        $totalDiscount = $invoices->sum('discount');
        $invoiceCount  = $invoices->count();

        $paymentBreakdown = $invoices->flatMap->payments
            ->groupBy(fn($p) => $p->account->name ?? 'Unknown')
            ->map(fn($g) => $g->sum('amount'));

        return response()->json([
            'date'             => $today,
            'invoice_count'    => $invoiceCount,
            'total_sales'      => $totalSales,
            'total_received'   => $totalReceived,
            'total_discount'   => $totalDiscount,
            'payment_breakdown'=> $paymentBreakdown,
            'invoices'         => $invoices->map(fn($inv) => [
                'invoice_no' => $inv->invoice_no,
                'amount'     => $inv->net_amount,
                'paid'       => $inv->paid_amount,
            ]),
        ]);
    }

    // ── Receipt ───────────────────────────────────────────────────────

    public function receipt($id)
    {
        $invoice = SaleInvoice::with([
            'account', 'items.product', 'items.variation', 'items.measurementUnit', 'payments.account',
        ])->findOrFail($id);

        $pdf = new \TCPDF('P', 'mm', [80, 200], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(4, 4, 4);
        $pdf->SetAutoPageBreak(true, 4);
        $pdf->AddPage();
        $pdf->setCellPadding(1);

        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 20, 4, 40);
            $pdf->Ln(18);
        }

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 5, 'JILD', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell(0, 4, 'Sale Receipt', 0, 1, 'C');
        $pdf->Cell(0, 4, Carbon::parse($invoice->date)->format('d/m/Y H:i'), 0, 1, 'C');
        $pdf->Cell(0, 4, 'Invoice: ' . $invoice->invoice_no, 0, 1, 'C');
        if ($invoice->account) {
            $pdf->Cell(0, 4, 'Customer: ' . $invoice->account->name, 0, 1, 'C');
        }

        $pdf->Ln(2);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell(72, 0.3, '', 'T', 1); // divider

        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->Cell(35, 5, 'Item', 0, 0);
        $pdf->Cell(10, 5, 'Qty', 0, 0, 'R');
        $pdf->Cell(12, 5, 'Price', 0, 0, 'R');
        $pdf->Cell(15, 5, 'Total', 0, 1, 'R');

        $pdf->Cell(72, 0.3, '', 'T', 1);
        $pdf->SetFont('helvetica', '', 7);

        $subTotal = 0;
        foreach ($invoice->items as $item) {
            $lineTotal = $item->getLineTotal();
            $subTotal += $lineTotal;
            $name = mb_strimwidth($item->product->name ?? '-', 0, 20, '..');
            if ($item->variation) $name .= ' (' . $item->variation->sku . ')';
            $pdf->Cell(35, 5, $name, 0, 0);
            $pdf->Cell(10, 5, number_format($item->quantity, 0), 0, 0, 'R');
            $pdf->Cell(12, 5, number_format($item->sale_price, 0), 0, 0, 'R');
            $pdf->Cell(15, 5, number_format($lineTotal, 0), 0, 1, 'R');
        }

        $pdf->Cell(72, 0.3, '', 'T', 1);
        $pdf->SetFont('helvetica', '', 7);

        if ($invoice->discount > 0) {
            $pdf->Cell(57, 5, 'Discount', 0, 0);
            $pdf->Cell(15, 5, '(-' . number_format($invoice->discount, 0) . ')', 0, 1, 'R');
        }

        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(57, 5, 'TOTAL', 0, 0);
        $pdf->Cell(15, 5, 'PKR ' . number_format($invoice->net_amount, 0), 0, 1, 'R');

        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell(57, 5, 'Paid', 0, 0);
        $pdf->Cell(15, 5, number_format($invoice->paid_amount, 0), 0, 1, 'R');

        if ($invoice->balance > 0) {
            $pdf->Cell(57, 5, 'Balance Due', 0, 0);
            $pdf->Cell(15, 5, number_format($invoice->balance, 0), 0, 1, 'R');
        }

        foreach ($invoice->payments as $payment) {
            $pdf->Cell(57, 4, '  via ' . ($payment->account->name ?? '-'), 0, 0);
            $pdf->Cell(15, 4, number_format($payment->amount, 0), 0, 1, 'R');
        }

        $pdf->Cell(72, 0.3, '', 'T', 1);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell(0, 5, 'Thank you for your purchase!', 0, 1, 'C');
        $pdf->Cell(0, 4, 'Powered by Jild ERP', 0, 1, 'C');

        return $pdf->Output('receipt_' . $invoice->invoice_no . '.pdf', 'I');
    }

    // ── Accounting ────────────────────────────────────────────────────

    private function postSaleEntries(SaleInvoice $invoice): void
    {
        if (!$invoice->account_id && $invoice->payments->isEmpty()) return;

        $entries = [];

        if ($invoice->sub_total > 0 && $invoice->account_id) {
            $entries[] = [
                'dr_id'   => $invoice->account_id,
                'cr'      => '401001',
                'amount'  => $invoice->sub_total,
                'remarks' => 'POS Sale — ' . $invoice->invoice_no,
            ];
        }

        if ($invoice->discount > 0 && $invoice->account_id) {
            $entries[] = [
                'dr'      => '401003',
                'cr_id'   => $invoice->account_id,
                'amount'  => $invoice->discount,
                'remarks' => 'POS discount — ' . $invoice->invoice_no,
            ];
        }

        foreach ($invoice->payments as $payment) {
            if ($payment->amount > 0) {
                $crId = $invoice->account_id;
                if (!$crId) {
                    // Walk-in: DR cash/bank, CR revenue directly
                    $entries[] = [
                        'dr_id'   => $payment->account_id,
                        'cr'      => '401001',
                        'amount'  => $payment->amount,
                        'remarks' => 'POS cash sale — ' . $invoice->invoice_no,
                    ];
                } else {
                    $entries[] = [
                        'dr_id'   => $payment->account_id,
                        'cr_id'   => $crId,
                        'amount'  => $payment->amount,
                        'remarks' => 'POS payment — ' . $invoice->invoice_no,
                    ];
                }
            }
        }

        if (empty($entries)) return;

        $this->syncVoucherEntries($invoice, 'sale', $invoice->date, $entries);
    }
}