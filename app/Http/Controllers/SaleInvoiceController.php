<?php

namespace App\Http\Controllers;

use App\Models\SaleInvoice;
use App\Models\SaleInvoiceItem;
use App\Models\SalePayment;
use App\Models\Product;
use App\Models\MeasurementUnit;
use App\Models\ChartOfAccounts;
use App\Traits\PostsAccountingEntries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SaleInvoiceController extends Controller
{
    use PostsAccountingEntries;

    public function index()
    {
        $invoices = SaleInvoice::with(['account', 'items', 'payments'])
            ->latest()->get();
        return view('sales.index', compact('invoices'));
    }

    public function create()
    {
        $products = Product::get();
        $customers = ChartOfAccounts::where('account_type', 'customer')->get();
        $accounts  = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->get();
        $units     = MeasurementUnit::all();
        return view('sales.create', compact('products', 'customers', 'accounts', 'units'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'date'                    => 'required|date',
            'account_id'              => 'nullable|exists:chart_of_accounts,id',
            'type'                    => 'required|in:cash,credit',
            'payment_terms'           => 'nullable|string',
            'ref_no'                  => 'nullable|string|max:100',
            'remarks'                 => 'nullable|string',
            'discount'                => 'nullable|numeric|min:0',
            'convance_charges'        => 'nullable|numeric|min:0',
            'items'                   => 'required|array|min:1',
            'items.*.product_id'      => 'required|exists:products,id',
            'items.*.variation_id'    => 'nullable|exists:product_variations,id',
            'items.*.quantity'        => 'required|numeric|min:0.01',
            'items.*.unit'            => 'required|exists:measurement_units,id',
            'items.*.sale_price'      => 'required|numeric|min:0',
            'items.*.discount'        => 'nullable|numeric|min:0|max:100',
            // Payment (optional at creation)
            'payment_account_id'      => 'nullable|exists:chart_of_accounts,id',
            'payment_amount'          => 'nullable|numeric|min:0',
            'payment_date'            => 'nullable|date',
            'payment_reference'       => 'nullable|string|max:100',
        ]);

        DB::beginTransaction();
        try {
            $invoiceNo = 'SI-' . str_pad(SaleInvoice::withTrashed()->count() + 1, 5, '0', STR_PAD_LEFT);
            $subTotal  = $this->calcSubTotal($request->items);
            $discount  = (float)($request->discount ?? 0);
            $conveyance = (float)($request->convance_charges ?? 0);
            $netAmount  = $subTotal - $discount + $conveyance;

            $invoice = SaleInvoice::create([
                'invoice_no'       => $invoiceNo,
                'date'             => $request->date,
                'account_id'       => $request->account_id,
                'type'             => $request->type,
                'payment_terms'    => $request->payment_terms,
                'ref_no'           => $request->ref_no,
                'remarks'          => $request->remarks,
                'sub_total'        => $subTotal,
                'discount'         => $discount,
                'convance_charges' => $conveyance,
                'net_amount'       => $netAmount,
                'paid_amount'      => 0,
                'balance'          => $netAmount,
                'payment_status'   => 'unpaid',
                'created_by'       => auth()->id(),
            ]);

            $this->saveItems($invoice, $request->items);

            // Save initial payment if provided
            if ($request->filled('payment_account_id') && $request->payment_amount > 0) {
                $this->savePayment($invoice, [
                    'account_id'   => $request->payment_account_id,
                    'payment_date' => $request->payment_date ?? $request->date,
                    'amount'       => $request->payment_amount,
                    'reference'    => $request->payment_reference,
                    'remarks'      => 'Initial payment on invoice creation',
                ]);
            }

            $invoice->recalculatePaymentStatus();
            $invoice->load(['items.product', 'payments']);
            $this->postSaleEntries($invoice);

            DB::commit();
            Log::info('[Sale] Invoice created', ['id' => $invoice->id]);
            return redirect()->route('sale_invoices.index')
                ->with('success', 'Sale Invoice created successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Sale] Store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to create invoice: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $invoice   = SaleInvoice::with(['items', 'payments'])->findOrFail($id);
        $products = Product::get();
        $customers = ChartOfAccounts::where('account_type', 'customer')->get();
        $accounts  = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->get();
        $units     = MeasurementUnit::all();
        return view('sales.edit', compact('invoice', 'products', 'customers', 'accounts', 'units'));
    }

    public function show($id)
    {
        $invoice  = SaleInvoice::with(['account', 'items.product', 'items.variation', 'items.measurementUnit', 'payments.account'])->findOrFail($id);
        $accounts = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->get();
        return view('sales.show', compact('invoice', 'accounts'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'date'                 => 'required|date',
            'account_id'           => 'nullable|exists:chart_of_accounts,id',
            'type'                 => 'required|in:cash,credit',
            'payment_terms'        => 'nullable|string',
            'ref_no'               => 'nullable|string|max:100',
            'remarks'              => 'nullable|string',
            'discount'             => 'nullable|numeric|min:0',
            'convance_charges'     => 'nullable|numeric|min:0',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.unit'         => 'required|exists:measurement_units,id',
            'items.*.sale_price'   => 'required|numeric|min:0',
            'items.*.discount'     => 'nullable|numeric|min:0|max:100',
        ]);

        DB::beginTransaction();
        try {
            $invoice    = SaleInvoice::findOrFail($id);
            $subTotal   = $this->calcSubTotal($request->items);
            $discount   = (float)($request->discount ?? 0);
            $conveyance = (float)($request->convance_charges ?? 0);
            $netAmount  = $subTotal - $discount + $conveyance;

            $invoice->update([
                'date'             => $request->date,
                'account_id'       => $request->account_id,
                'type'             => $request->type,
                'payment_terms'    => $request->payment_terms,
                'ref_no'           => $request->ref_no,
                'remarks'          => $request->remarks,
                'sub_total'        => $subTotal,
                'discount'         => $discount,
                'convance_charges' => $conveyance,
                'net_amount'       => $netAmount,
            ]);

            $invoice->items()->delete();
            $this->saveItems($invoice, $request->items);
            $invoice->recalculatePaymentStatus();

            $invoice->load(['items.product', 'payments']);

            $this->postSaleEntries($invoice);

            DB::commit();
            Log::info('[Sale] Invoice updated', ['id' => $invoice->id]);
            return redirect()->route('sale_invoices.index')
                ->with('success', 'Sale Invoice updated successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Sale] Update failed', ['error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Failed to update invoice: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $invoice = SaleInvoice::findOrFail($id);
            $this->deleteVoucherEntries($invoice);
            $invoice->items()->delete();
            $invoice->payments()->delete();
            $invoice->delete();
            DB::commit();
            return redirect()->route('sale_invoices.index')
                ->with('success', 'Sale Invoice deleted.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    // ── Payment Management ────────────────────────────────────────────

    public function addPayment(Request $request, $id)
    {
        $request->validate([
            'account_id'   => 'required|exists:chart_of_accounts,id',
            'payment_date' => 'required|date',
            'amount'       => 'required|numeric|min:0.01',
            'reference'    => 'nullable|string|max:100',
            'remarks'      => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $invoice = SaleInvoice::findOrFail($id);

            if ($request->amount > $invoice->balance) {
                return back()->with('error', 'Payment amount exceeds outstanding balance.');
            }

            $payment = $this->savePayment($invoice, $request->all());
            $invoice->recalculatePaymentStatus();
            $invoice->load(['items', 'payments']);
            $this->postSaleEntries($invoice);

            DB::commit();
            return back()->with('success', 'Payment of PKR ' . number_format($request->amount, 2) . ' recorded.');

        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to save payment: ' . $e->getMessage());
        }
    }

    public function updatePayment(Request $request, $invoiceId, $paymentId)
    {
        $request->validate([
            'account_id'   => 'required|exists:chart_of_accounts,id',
            'payment_date' => 'required|date',
            'amount'       => 'required|numeric|min:0.01',
            'reference'    => 'nullable|string|max:100',
            'remarks'      => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $payment = SalePayment::where('sale_invoice_id', $invoiceId)->findOrFail($paymentId);
            $this->deletePaymentVoucherEntry($payment);

            $payment->update([
                'account_id'   => $request->account_id,
                'payment_date' => $request->payment_date,
                'amount'       => $request->amount,
                'reference'    => $request->reference,
                'remarks'      => $request->remarks,
            ]);

            $invoice = SaleInvoice::findOrFail($invoiceId);
            $invoice->recalculatePaymentStatus();
            $invoice->load(['items', 'payments']);
            $this->postSaleEntries($invoice);

            DB::commit();
            return back()->with('success', 'Payment updated successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to update payment: ' . $e->getMessage());
        }
    }

    public function deletePayment($invoiceId, $paymentId)
    {
        DB::beginTransaction();
        try {
            $payment = SalePayment::where('sale_invoice_id', $invoiceId)->findOrFail($paymentId);
            $this->deletePaymentVoucherEntry($payment);
            $payment->delete();

            $invoice = SaleInvoice::findOrFail($invoiceId);
            $invoice->recalculatePaymentStatus();
            $invoice->load(['items', 'payments']);
            $this->postSaleEntries($invoice);

            DB::commit();
            return back()->with('success', 'Payment deleted.');

        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to delete payment.');
        }
    }

    // ── Print ─────────────────────────────────────────────────────────

    public function print($id)
    {
        $invoice = SaleInvoice::with([
            'account', 'items.product', 'items.variation', 'items.measurementUnit', 'payments.account',
        ])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Jild');
        $pdf->SetAuthor('Jild');
        $pdf->SetTitle('Sale Invoice #' . $invoice->invoice_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) $pdf->Image($logoPath, 10, 10, 30);

        $statusColor = match($invoice->payment_status) {
            'paid'    => '#28a745',
            'partial' => '#ffc107',
            default   => '#dc3545',
        };

        $pdf->SetXY(130, 12);
        $pdf->writeHTML('
            <table border="1" cellpadding="4" style="font-size:10px;line-height:14px;border-collapse:collapse;">
                <tr><td><b>Invoice #</b></td><td>' . $invoice->invoice_no . '</td></tr>
                <tr><td><b>Date</b></td><td>' . Carbon::parse($invoice->date)->format('d/m/Y') . '</td></tr>
                <tr><td><b>Customer</b></td><td>' . ($invoice->account->name ?? 'Walk-in') . '</td></tr>
                <tr><td><b>Type</b></td><td>' . ucfirst($invoice->type) . '</td></tr>
                <tr><td><b>Status</b></td><td><span style="color:' . $statusColor . '">' . ucfirst($invoice->payment_status) . '</span></td></tr>
            </table>',
        false, false, false, false, '');

        $pdf->Line(60, 52.25, 200, 52.25);
        $pdf->SetXY(10, 48);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(50, 8, 'Sale Invoice', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        $html = '
        <table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <th width="6%">S.No</th>
                <th width="24%">Item</th>
                <th width="20%">Variation</th>
                <th width="15%">Qty</th>
                <th width="12%">Price</th>
                <th width="8%">Disc%</th>
                <th width="15%">Total</th>
            </tr>';

        $count = 0; $subTotal = 0;
        foreach ($invoice->items as $item) {
            $count++;
            $lineTotal = $item->getLineTotal();
            $subTotal += $lineTotal;
            $html .= '
            <tr>
                <td>' . $count . '</td>
                <td>' . ($item->product->name ?? '-') . '</td>
                <td>' . ($item->variation->sku ?? '-') . '</td>
                <td>' . number_format($item->quantity, 2) . ' ' . ($item->measurementUnit->shortcode ?? '') . '</td>
                <td align="right">' . number_format($item->sale_price, 2) . '</td>
                <td>' . ($item->discount ?? 0) . '%</td>
                <td align="right">' . number_format($lineTotal, 2) . '</td>
            </tr>';
        }

        $html .= '<tr><td colspan="6" align="right"><b>Sub Total</b></td><td align="right"><b>' . number_format($subTotal, 2) . '</b></td></tr>';

        if ($invoice->convance_charges > 0) {
            $html .= '<tr><td colspan="6" align="right">Conveyance</td><td align="right">' . number_format($invoice->convance_charges, 2) . '</td></tr>';
        }
        if ($invoice->discount > 0) {
            $html .= '<tr><td colspan="6" align="right">Bill Discount</td><td align="right">(' . number_format($invoice->discount, 2) . ')</td></tr>';
        }

        $html .= '<tr style="background-color:#f5f5f5;"><td colspan="6" align="right"><b>Net Total</b></td><td align="right"><b>' . number_format($invoice->net_amount, 2) . '</b></td></tr>';
        $html .= '<tr><td colspan="6" align="right">Paid</td><td align="right" style="color:green;">' . number_format($invoice->paid_amount, 2) . '</td></tr>';
        $html .= '<tr><td colspan="6" align="right"><b>Balance</b></td><td align="right" style="color:red;"><b>' . number_format($invoice->balance, 2) . '</b></td></tr>';
        $html .= '</table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        if (!empty($invoice->remarks)) {
            $pdf->writeHTML('<b>Remarks:</b><br>' . nl2br($invoice->remarks), true, false, true, false, '');
        }

        $pdf->Ln(20);
        $y = $pdf->GetY();
        $pdf->Line(28, $y, 68, $y);
        $pdf->Line(130, $y, 170, $y);
        $pdf->SetXY(28,  $y + 2); $pdf->Cell(40, 6, 'Customer Signature', 0, 0, 'C');
        $pdf->SetXY(130, $y + 2); $pdf->Cell(40, 6, 'Authorized By',       0, 0, 'C');

        return $pdf->Output($invoice->invoice_no.'.pdf', 'I');
    }

    // ── Private helpers ───────────────────────────────────────────────

    private function calcSubTotal(array $items): float
    {
        $total = 0;
        foreach ($items as $item) {
            $price    = (float)($item['sale_price'] ?? 0);
            $disc     = (float)($item['discount']   ?? 0);
            $qty      = (float)($item['quantity']   ?? 0);
            $total   += ($price - ($price * $disc / 100)) * $qty;
        }
        return round($total, 2);
    }

    private function saveItems(SaleInvoice $invoice, array $items): void
    {
        $productNames = Product::pluck('name', 'id');
        foreach ($items as $item) {
            if (empty($item['product_id'])) continue;
            $invoice->items()->create([
                'product_id'   => $item['product_id'],
                'variation_id' => $item['variation_id'] ?? null,
                'item_name'    => $productNames[$item['product_id']] ?? null,
                'sale_price'   => $item['sale_price']   ?? 0,
                'discount'     => $item['discount']     ?? 0,
                'quantity'     => $item['quantity']     ?? 0,
                'unit'         => $item['unit'],
                'remarks'      => $item['item_remarks'] ?? null,
            ]);
        }
    }

    private function savePayment(SaleInvoice $invoice, array $data): SalePayment
    {
        return SalePayment::create([
            'sale_invoice_id' => $invoice->id,
            'account_id'      => $data['account_id'],
            'payment_date'    => $data['payment_date'],
            'amount'          => $data['amount'],
            'reference'       => $data['reference'] ?? null,
            'remarks'         => $data['remarks']   ?? null,
            'created_by'      => auth()->id(),
        ]);
    }

    private function deletePaymentVoucherEntry(SalePayment $payment): void
    {
        // Voucher entries for individual payments are bundled in the invoice sync
        // so no separate deletion needed — postSaleEntries handles the full sync
    }

    /**
     * Sale Invoice accounting:
     *   DR  Customer (AR)              ← net_amount receivable
     *   CR  Sales Revenue (401001)     ← sub total
     *   CR  Sales Discount (401003)    ← bill discount
     *   DR  Conveyance (502001)        ← if charged to us
     *
     * Per payment received:
     *   DR  Cash/Bank Account          ← payment received
     *   CR  Customer (AR)              ← reduces receivable
     */
    private function postSaleEntries(SaleInvoice $invoice): void
    {
        if (!$invoice->account_id) return;

        $entries = [];

        // ── Entry 1: Customer DR / Sales Revenue CR ───────────────────────
        if ($invoice->sub_total > 0) {
            $entries[] = [
                'dr_id'   => $invoice->account_id,
                'cr'      => '401001',
                'amount'  => $invoice->sub_total,
                'remarks' => 'Sale revenue — ' . $invoice->invoice_no,
            ];
        }

        // ── Entry 2: Bill Discount ────────────────────────────────────────
        if ($invoice->discount > 0) {
            $entries[] = [
                'dr'      => '401003',
                'cr_id'   => $invoice->account_id,
                'amount'  => $invoice->discount,
                'remarks' => 'Sale discount — ' . $invoice->invoice_no,
            ];
        }

        // ── Entry 3: COGS DR / Stock in Hand CR ───────────────────────────
        // Calculated per line item using average purchase cost for that
        // specific variation (or product-level if no variation).
        $totalCogs = 0;

        foreach ($invoice->items as $item) {
            $productId   = $item->product_id;
            $variationId = $item->variation_id;
            $qty         = (float) $item->quantity;

            // Build purchase cost query
            $pq = \App\Models\PurchaseInvoiceItem::where('item_id', $productId);

            if ($variationId) {
                $hasVarSpecific = (clone $pq)->where('variation_id', $variationId)->exists();

                if ($hasVarSpecific) {
                    $pq = $pq->where('variation_id', $variationId);
                } else {
                    // Fall back to null-variation purchases for this product
                    $pq = $pq->whereNull('variation_id');
                }
            }
            // If no variation at all — query runs on item_id only (correct)

            $agg     = (clone $pq)->selectRaw('SUM(quantity * price) as v, SUM(quantity) as q')->first();
            $avgCost = ($agg && $agg->q > 0)
                ? ($agg->v / $agg->q)
                : (float) ($item->product->manufacturing_cost ?? 0); // fallback for manufactured items

            $totalCogs += round($avgCost * $qty, 2);
        }

        if ($totalCogs > 0) {
            $entries[] = [
                'dr'      => '501001', // Cost of Goods Sold
                'cr'      => '104001', // Stock in Hand
                'amount'  => $totalCogs,
                'remarks' => 'COGS — ' . $invoice->invoice_no,
            ];
        }

        // ── Entry 4: Each payment received — Cash/Bank DR / Customer CR ──
        foreach ($invoice->payments as $payment) {
            if ($payment->amount > 0) {
                $entries[] = [
                    'dr_id'   => $payment->account_id,
                    'cr_id'   => $invoice->account_id,
                    'amount'  => $payment->amount,
                    'remarks' => 'Payment received — ' . $invoice->invoice_no,
                ];
            }
        }

        if (empty($entries)) return;

        $this->syncVoucherEntries(
            $invoice,
            'sale',
            $invoice->date,
            $entries
        );

        Log::info('[Sale] Accounting synced', [
            'invoice_id' => $invoice->id,
            'cogs'       => $totalCogs,
        ]);
    }
}