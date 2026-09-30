<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SaleInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\ChartOfAccounts;
use App\Models\Product;
use App\Models\Location;
use App\Services\Inventory;
use App\Traits\PostsAccountingEntries;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SaleReturnController extends Controller
{
    use PostsAccountingEntries;

    public function index()
    {
        $returns = SaleReturn::with(['customer','items.product','items.variation'])->latest()->get()
            ->map(function ($return) {
                $return->total_amount = $return->items->sum(function ($item) {
                    return $item->qty * $item->price; // adjust field name
                });
                return $return;
            });

        return view('sale_returns.index', compact('returns'));
    }

    public function create()
    {
        return view('sale_returns.create', [
            'products'          => Product::get(),
            'customers'         => ChartOfAccounts::where('account_type', 'customer')->get(),
            'invoices'          => SaleInvoice::latest()->get(), // optional link to original
            'refundAccounts'    => ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->orderBy('name')->get(),
            'locationGroups'    => Location::grouped(),
            'defaultLocationId' => Location::defaultId(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id'          => 'required|exists:chart_of_accounts,id',
            'location_id'          => 'required|exists:locations,id',
            'return_date'          => 'required|date',
            'sale_invoice_no'      => 'nullable|string|max:50',
            'remarks'              => 'nullable|string|max:500',
            'refund_amount'        => 'nullable|numeric|min:0',
            'refund_account_id' => ['nullable', 'exists:chart_of_accounts,id', function ($attribute, $value, $fail) use ($request) {
                    if ((float) $request->input('refund_amount', 0) > 0 && empty($value)) {
                        $fail('Please select a refund account when a refund amount is entered.');
                    }
            }],
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.qty'          => 'required|numeric|min:0.01',
            'items.*.price'        => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            Log::info('[SaleReturn] Store request received', [
                'user_id' => Auth::id(),
                'payload' => $validated,
            ]);

            // Create Sale Return
            $return = SaleReturn::create([
                'account_id'         => $validated['customer_id'],
                'location_id'        => $validated['location_id'],
                'return_date'        => $validated['return_date'],
                'sale_invoice_no'    => $validated['sale_invoice_no'] ?? null,
                'remarks'            => $validated['remarks'] ?? null,
                'refund_amount'      => $validated['refund_amount'] ?? 0,
                'refund_account_id'  => $validated['refund_account_id'] ?? null,
                'created_by'         => Auth::id(),
            ]);

            Log::info('[SaleReturn] Main record created', [
                'return_id' => $return->id,
                'customer'  => $return->customer_id,
            ]);

            // Create Sale Return Items
            foreach ($validated['items'] as $idx => $item) {
                try {
                    $savedItem = SaleReturnItem::create([
                        'sale_return_id' => $return->id,
                        'product_id'     => $item['product_id'],
                        'variation_id'   => $item['variation_id'] ?? null,
                        'qty'            => $item['qty'],
                        'price'          => $item['price'],
                        'unit_cost'      => Inventory::avgCost((int) $item['product_id'], !empty($item['variation_id']) ? (int) $item['variation_id'] : null, $validated['return_date']),
                    ]);

                    Log::debug('[SaleReturn] Item created', [
                        'return_id'  => $return->id,
                        'item_index' => $idx,
                        'item_id'    => $savedItem->id,
                        'product_id' => $item['product_id'],
                    ]);
                } catch (\Throwable $itemEx) {
                    Log::error('[SaleReturn] Item save failed', [
                        'return_id'  => $return->id,
                        'item_index' => $idx,
                        'error'      => $itemEx->getMessage(),
                    ]);
                    throw $itemEx; // rethrow so transaction rolls back
                }
            }

            // ── Post accounting entries (reverse revenue + COGS) ──────────
            $return->load('items.product');
            $this->postSaleReturnEntries($return);

            DB::commit();
            Log::info('[SaleReturn] Completed successfully', [
                'return_id' => $return->id,
                'by'        => Auth::id(),
            ]);

            return redirect()
                ->route('sale_return.index')
                ->with('success', 'Sale return created successfully.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SaleReturn] Store failed', [
                'user_id'   => Auth::id(),
                'payload'   => $request->all(), // raw input for debugging
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return back()
                ->withInput()
                ->with('error', 'Error saving sale return. Please contact administrator.');
        }
    }

    public function edit($id)
    {
        $return = SaleReturn::with(['items.product', 'items.variation'])->findOrFail($id);

        return view('sale_returns.edit', [
            'return'            => $return,
            'products'          => Product::get(),
            'customers'         => ChartOfAccounts::where('account_type', 'customer')->get(),
            'invoices'          => SaleInvoice::latest()->get(),
            'refundAccounts'    => ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->orderBy('name')->get(),
            'locationGroups'    => Location::grouped(),
            'defaultLocationId' => Location::defaultId(),
        ]);
    }

    public function update(Request $request, $id)
    {
        // Log the incoming request
        Log::info('[SaleReturn] Update Request', [
            'sale_return_id' => $id,
            'request_data'   => $request->except(['_token', '_method']),
        ]);

        $validated = $request->validate([
            'account_id'           => 'required|exists:chart_of_accounts,id',
            'location_id'          => 'required|exists:locations,id',
            'return_date'          => 'required|date',
            'sale_invoice_no'      => 'nullable|string|max:50',
            'remarks'              => 'nullable|string|max:500',
            'refund_amount'        => 'nullable|numeric|min:0',
            'refund_account_id' => ['nullable', 'exists:chart_of_accounts,id', function ($attribute, $value, $fail) use ($request) {
                if ((float) $request->input('refund_amount', 0) > 0 && empty($value)) {
                    $fail('Please select a refund account when a refund amount is entered.');
                }
            }],
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.qty'          => 'required|numeric|min:0.01',
            'items.*.price'        => 'required|numeric|min:0',
        ]);

        // Log validated data
        Log::info('[SaleReturn] Validated Data', $validated);

        DB::beginTransaction();
        try {
            $return = SaleReturn::findOrFail($id);

            $return->update([
                'account_id'         => $validated['account_id'],
                'location_id'        => $validated['location_id'],
                'return_date'        => $validated['return_date'],
                'sale_invoice_no'    => $validated['sale_invoice_no'] ?? null,
                'remarks'            => $validated['remarks'] ?? null,
                'refund_amount'      => $validated['refund_amount'] ?? 0,
                'refund_account_id'  => $validated['refund_account_id'] ?? null,
            ]);

            // Delete old items and reinsert
            $return->items()->delete();

            foreach ($validated['items'] as $idx => $item) {
                SaleReturnItem::create([
                    'sale_return_id' => $return->id,
                    'product_id'     => $item['product_id'],
                    'variation_id'   => $item['variation_id'] ?? null,
                    'qty'            => $item['qty'],
                    'price'          => $item['price'],
                    'unit_cost'      => Inventory::avgCost((int) $item['product_id'], !empty($item['variation_id']) ? (int) $item['variation_id'] : null, $validated['return_date']),
                ]);
            }

            // ── Re-sync accounting entries with updated items ─────────────
            $return->load('items.product');
            $this->postSaleReturnEntries($return);

            DB::commit();

            Log::info('[SaleReturn] Update Success', [
                'sale_return_id' => $return->id,
                'items_count'    => count($validated['items']),
            ]);

            return redirect()->route('sale_return.index')
                ->with('success', 'Sale return updated successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('[SaleReturn] Update Failed', [
                'sale_return_id' => $id,
                'error_message'  => $e->getMessage(),
                'file'           => $e->getFile(),
                'line'           => $e->getLine(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return back()->withInput()
                ->with('error', 'Error updating sale return.');
        }
    }

    public function show($id)
    {
        $return = SaleReturn::with('items.product', 'items.variation', 'customer', 'location')->findOrFail($id);
        return response()->json($return);
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $return = SaleReturn::findOrFail($id);

            // ── Remove accounting entries before deleting the return ──────
            $this->deleteVoucherEntries($return);
            Inventory::clear($return);

            $return->items()->delete();
            $return->delete();

            DB::commit();
            return redirect()->route('sale_return.index')->with('success','Sale return deleted.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SaleReturn] Delete failed', ['error'=>$e->getMessage()]);
            return back()->with('error','Error deleting sale return.');
        }
    }

    public function print($id)
    {
        $return = SaleReturn::with(['customer','items.product','items.variation'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Your App');
        $pdf->SetAuthor('Your Company');
        $pdf->SetTitle('Sale Return #'.$return->id);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        // --- Logo ---
        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) $pdf->Image($logoPath, 5, 11, 50);

        // --- Return Info Box ---
        $pdf->SetXY(130, 12);
        $returnInfo = '
        <table cellpadding="2" style="font-size:10px; line-height:14px;">
            <tr><td><b>Return #</b></td><td>'.$return->id.'</td></tr>
            <tr><td><b>Date</b></td><td>'.\Carbon\Carbon::parse($return->return_date)->format('d/m/Y').'</td></tr>
            <tr><td><b>Customer</b></td><td>'.($return->customer->name ?? '-').'</td></tr>
            <tr><td><b>Sale Invoice</b></td><td>'.($return->sale_invoice_no ?? '-').'</td></tr>
        </table>';
        $pdf->writeHTML($returnInfo, false, false, false, false, '');

        // --- Title Box ---
        $pdf->Line(60, 52.25, 200, 52.25);
        $pdf->SetXY(10, 48);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(50, 8, 'Sale Return', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);

        // --- Items Table ---
        $pdf->Ln(5);
        $html = '<table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5; font-weight:bold;">
                <th width="8%">S.No</th>
                <th width="25%">Product</th>
                <th width="30%">Variation</th>
                <th width="10%">Qty</th>
                <th width="12%">Price</th>
                <th width="15%">Total</th>
            </tr>';

        $count = 0;
        $totalAmount = 0;

        foreach ($return->items as $item) {
            $count++;
            $lineTotal = $item->qty * $item->price;
            $totalAmount += $lineTotal;

            $html .= '
            <tr>
                <td align="center">'.$count.'</td>
                <td>'.($item->product->name ?? '-').'</td>
                <td>'.($item->variation->sku ?? '-').'</td>
                <td align="center">'.number_format($item->qty, 2).'</td>
                <td align="right">'.number_format($item->price, 2).'</td>
                <td align="right">'.number_format($lineTotal, 2).'</td>
            </tr>';
        }

        // --- Totals ---
        $html .= '
            <tr>
                <td colspan="5" align="right"><b>Total</b></td>
                <td align="right"><b>'.number_format($totalAmount, 2).'</b></td>
            </tr>';

        if (!empty($return->discount)) {
            $totalAmount -= $return->discount;
            $html .= '
            <tr>
                <td colspan="5" align="right"><b>Return Discount</b></td>
                <td align="right">'.number_format($return->discount, 2).'</td>
            </tr>';
        }

        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="5" align="right"><b>Net Total</b></td>
                <td align="right"><b>'.number_format($totalAmount, 2).'</b></td>
            </tr>
        </table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        // --- Remarks ---
        if (!empty($return->remarks)) {
            $remarksHtml = '<b>Remarks:</b><br><span style="font-size:12px;">'.nl2br($return->remarks).'</span>';
            $pdf->writeHTML($remarksHtml, true, false, true, false, '');
        }

        // --- Signatures ---
        $pdf->Ln(20);
        $yPos = $pdf->GetY();
        $lineWidth = 40;

        $pdf->Line(28, $yPos, 28 + $lineWidth, $yPos);
        $pdf->Line(130, $yPos, 130 + $lineWidth, $yPos);

        $pdf->SetXY(28, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Received By', 0, 0, 'C');
        $pdf->SetXY(130, $yPos + 2);
        $pdf->Cell($lineWidth, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output('sale_return_'.$return->id.'.pdf', 'I');
    }

    // ── Private helpers ───────────────────────────────────────────────

    /**
     * Sale Return accounting (reverses portion of original sale):
     *   DR  Sales Revenue (401001)     ← reduce revenue by return amount (full subTotal)
     *   CR  Customer (AR)              ← portion of return NOT refunded in cash
     *   CR  Refund Account             ← portion of return paid back as cash/bank refund
     *   DR  Stock in Hand (104001)     ← stock comes back
     *   CR  COGS (501001)              ← reduce COGS
     *
     * refund_amount on the return determines the split:
     *   - refund_amount = 0            → entire subTotal credited to AR (old behavior)
     *   - refund_amount = subTotal     → entire subTotal paid out of refund_account_id
     *   - 0 < refund_amount < subTotal → split between the two
     *
     * refund_amount is clamped to subTotal so a typo can't refund more than
     * the return is actually worth.
     */
    /**
     * Sale return:
     *   DR Sales Return (401002)          CR Customer        ← receivable reduced
     *   DR Sales Return (401002)          CR Cash/Bank       ← cash refunded (optional)
     *   DR Stock @ return location        CR COGS (501001)   ← goods back in stock at avg cost
     * Stock ledger: + qty at the return location.
     */
    private function postSaleReturnEntries(SaleReturn $return): void
    {
        $location  = Location::find($return->location_id) ?? Location::default();
        $subTotal  = round($return->items->sum(fn ($i) => (float) $i->qty * (float) $i->price), 2);
        $totalCogs = round($return->items->sum(fn ($i) => (float) $i->qty * (float) $i->unit_cost), 2);
        $returnAcc = \App\Models\ChartOfAccounts::where('account_code', '401002')->exists() ? '401002' : '401001';

        $refundAmount = min((float) ($return->refund_amount ?? 0), $subTotal);
        $arCredit     = round($subTotal - ($return->refund_account_id ? $refundAmount : 0), 2);

        $entries = [];
        if ($arCredit > 0) {
            $entries[] = ['dr' => $returnAcc, 'cr_id' => $return->account_id, 'amount' => $arCredit,
                          'remarks' => 'Sale return #' . $return->id . ' — customer credited'];
        }
        if ($refundAmount > 0 && $return->refund_account_id) {
            $entries[] = ['dr' => $returnAcc, 'cr_id' => $return->refund_account_id, 'amount' => $refundAmount,
                          'remarks' => 'Sale return #' . $return->id . ' — cash refund'];
        }
        if ($totalCogs > 0) {
            $entries[] = ['dr_id' => $location->inventoryAccountId(), 'cr' => '501001', 'amount' => $totalCogs,
                          'remarks' => 'Sale return #' . $return->id . ' — stock back at ' . $location->name];
        }

        $this->syncVoucherEntries($return, 'sale_return', $return->return_date, $entries);

        Inventory::sync($return, (string) $return->return_date, $return->items->map(fn ($i) => [
            'product_id'   => $i->product_id,
            'variation_id' => $i->variation_id,
            'location_id'  => $location->id,
            'qty'          => (float) $i->qty,
            'unit_cost'    => (float) $i->unit_cost,
            'remarks'      => 'Sale return #' . $return->id,
        ])->all());

        Log::info('[SaleReturn] Accounting synced', [
            'return_id' => $return->id, 'sub_total' => $subTotal, 'refund' => $refundAmount, 'cogs' => $totalCogs,
        ]);
    }
}
