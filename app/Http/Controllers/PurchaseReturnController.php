<?php

namespace App\Http\Controllers;

use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\PurchaseInvoice;
use App\Models\Product;
use App\Models\MeasurementUnit;
use App\Models\ChartOfAccounts;
use App\Traits\PostsAccountingEntries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PurchaseReturnController extends Controller
{
    use PostsAccountingEntries;

    public function index()
    {
        $returns = PurchaseReturn::with('vendor')->latest()->get();
        return view('purchase-returns.index', compact('returns'));
    }

    public function create()
    {
        $products = Product::get();
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->get();
        $units    = MeasurementUnit::all();
        return view('purchase-returns.create', compact('products', 'vendors', 'units'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'return_date'          => 'required|date',
            'vendor_id'            => 'required|exists:chart_of_accounts,id',
            'bill_no'              => 'nullable|string|max:100',
            'ref_no'               => 'nullable|string|max:100',
            'remarks'              => 'nullable|string',
            'attachments.*'        => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items.*.item_id'      => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.invoice_id'   => 'nullable|exists:purchase_invoices,id',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.unit'         => 'required|exists:measurement_units,id',
            'items.*.price'        => 'required|numeric|min:0',
            'items.*.item_remarks' => 'nullable|string',
            'convance_charges'     => 'nullable|numeric|min:0',
            'bill_discount'        => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $returnNo = 'PR-' . str_pad(PurchaseReturn::withTrashed()->count() + 1, 5, '0', STR_PAD_LEFT);

            $return = PurchaseReturn::create([
                'vendor_id'        => $request->vendor_id,
                'return_date'      => $request->return_date,
                'return_no'        => $returnNo,
                'bill_no'          => $request->bill_no,
                'ref_no'           => $request->ref_no,
                'remarks'          => $request->remarks,
                'convance_charges' => $request->convance_charges ?? 0,
                'bill_discount'    => $request->bill_discount    ?? 0,
                'created_by'       => auth()->id(),
            ]);

            $this->saveItems($return, $request->items ?? []);
            $this->saveAttachments($return, $request);

            $return->loadMissing('items');
            $this->postPurchaseReturnEntries($return);

            DB::commit();
            Log::info('[PurchaseReturn] Created', ['id' => $return->id]);
            return redirect()->route('purchase_return.index')
                ->with('success', 'Purchase Return created successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[PurchaseReturn] Store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to create return: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $return   = PurchaseReturn::with(['items', 'attachments'])->findOrFail($id);
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->get();
        $products = Product::select('id', 'name', 'barcode', 'measurement_unit')->get();
        $units    = MeasurementUnit::all();
        return view('purchase-returns.edit', compact('return', 'vendors', 'products', 'units'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'return_date'          => 'required|date',
            'vendor_id'            => 'required|exists:chart_of_accounts,id',
            'bill_no'              => 'nullable|string|max:100',
            'ref_no'               => 'nullable|string|max:100',
            'remarks'              => 'nullable|string',
            'attachments.*'        => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items.*.item_id'      => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.invoice_id'   => 'nullable|exists:purchase_invoices,id',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'items.*.unit'         => 'required|exists:measurement_units,id',
            'items.*.price'        => 'required|numeric|min:0',
            'items.*.item_remarks' => 'nullable|string',
            'convance_charges'     => 'nullable|numeric|min:0',
            'bill_discount'        => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $return = PurchaseReturn::findOrFail($id);
            $return->update([
                'vendor_id'        => $request->vendor_id,
                'return_date'      => $request->return_date,
                'bill_no'          => $request->bill_no,
                'ref_no'           => $request->ref_no,
                'remarks'          => $request->remarks,
                'convance_charges' => $request->convance_charges ?? 0,
                'bill_discount'    => $request->bill_discount    ?? 0,
            ]);

            $return->items()->delete();
            $this->saveItems($return, $request->items ?? []);
            $this->saveAttachments($return, $request);

            $return->loadMissing('items');
            $this->postPurchaseReturnEntries($return);

            DB::commit();
            Log::info('[PurchaseReturn] Updated', ['id' => $return->id]);
            return redirect()->route('purchase_return.index')
                ->with('success', 'Purchase Return updated successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[PurchaseReturn] Update failed', ['id' => $id, 'error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Failed to update return: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $return = PurchaseReturn::with('attachments')->findOrFail($id);
            $this->deleteVoucherEntries($return);
            foreach ($return->attachments as $attachment) {
                Storage::disk('public')->delete($attachment->file_path);
            }
            $return->delete();
            DB::commit();
            return redirect()->route('purchase_return.index')
                ->with('success', 'Purchase Return deleted successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[PurchaseReturn] Destroy failed', ['id' => $id, 'error' => $e->getMessage()]);
            return back()->with('error', 'Failed to delete return.');
        }
    }

    public function print($id)
    {
        $return = PurchaseReturn::with([
            'vendor',
            'items.product',
            'items.variation',
            'items.measurementUnit',
            'items.purchaseInvoice',
        ])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Jild');
        $pdf->SetAuthor('Jild');
        $pdf->SetTitle('Purchase Return #' . $return->id);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) $pdf->Image($logoPath, 5, 11, 50);

        $pdf->SetXY(130, 12);
        $pdf->writeHTML('
            <table border="1" cellpadding="4" style="font-size:10px;line-height:14px;border-collapse:collapse;">
                <tr><td><b>Return #</b></td><td>' . ($return->return_no ?? $return->id) . '</td></tr>
                <tr><td><b>Date</b></td><td>' . Carbon::parse($return->return_date)->format('d/m/Y') . '</td></tr>
                <tr><td><b>Bill No</b></td><td>' . ($return->bill_no ?? '-') . '</td></tr>
                <tr><td><b>Vendor</b></td><td>' . ($return->vendor->name ?? '-') . '</td></tr>
            </table>',
        false, false, false, false, '');

        $pdf->Line(60, 52.25, 200, 52.25);
        $pdf->SetXY(10, 48);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(50, 8, 'Purchase Return', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        $html = '
        <table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <th width="6%">S.No</th>
                <th width="20%">Item</th>
                <th width="18%">Variation</th>
                <th width="14%">Invoice #</th>
                <th width="17%">Qty</th>
                <th width="12%">Rate</th>
                <th width="13%">Total</th>
            </tr>';

        $count = 0; $totalAmount = 0;

        foreach ($return->items as $item) {
            $count++;
            $amount       = $item->quantity * $item->price;
            $totalAmount += $amount;
            $html .= '
            <tr>
                <td>' . $count . '</td>
                <td>' . ($item->product->name ?? '-') . '</td>
                <td>' . ($item->variation->sku ?? '-') . '</td>
                <td>' . ($item->purchaseInvoice ? '#' . $item->purchaseInvoice->id : '-') . '</td>
                <td>' . number_format($item->quantity, 2) . ' ' . ($item->measurementUnit->shortcode ?? '') . '</td>
                <td align="right">' . number_format($item->price, 2) . '</td>
                <td align="right">' . number_format($amount, 2) . '</td>
            </tr>';
        }

        $html .= '<tr><td colspan="6" align="right"><b>Sub Total</b></td><td align="right"><b>' . number_format($totalAmount, 2) . '</b></td></tr>';

        if ($return->convance_charges > 0) {
            $totalAmount += $return->convance_charges;
            $html .= '<tr><td colspan="6" align="right">Conveyance</td><td align="right">' . number_format($return->convance_charges, 2) . '</td></tr>';
        }
        if ($return->bill_discount > 0) {
            $totalAmount -= $return->bill_discount;
            $html .= '<tr><td colspan="6" align="right">Discount</td><td align="right">(' . number_format($return->bill_discount, 2) . ')</td></tr>';
        }

        $html .= '<tr style="background-color:#f5f5f5;"><td colspan="6" align="right"><b>Net Total</b></td><td align="right"><b>' . number_format($totalAmount, 2) . '</b></td></tr></table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        if (!empty($return->remarks)) {
            $pdf->writeHTML('<b>Remarks:</b><br><span style="font-size:12px;">' . nl2br($return->remarks) . '</span>', true, false, true, false, '');
        }

        $pdf->Ln(20);
        $y = $pdf->GetY();
        $pdf->Line(28, $y, 68, $y);
        $pdf->Line(130, $y, 170, $y);
        $pdf->SetXY(28,  $y + 2); $pdf->Cell(40, 6, 'Returned By',   0, 0, 'C');
        $pdf->SetXY(130, $y + 2); $pdf->Cell(40, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output('purchase_return_' . $return->id . '.pdf', 'I');
    }

    // ── Private helpers ───────────────────────────────────────────────

    private function saveItems(PurchaseReturn $return, array $items): void
    {
        $productNames = Product::pluck('name', 'id');
        foreach ($items as $itemData) {
            if (empty($itemData['item_id'])) continue;
            $return->items()->create([
                'item_id'             => $itemData['item_id'],
                'variation_id'        => $itemData['variation_id']  ?? null,
                'purchase_invoice_id' => $itemData['invoice_id']    ?? null,
                'item_name'           => $productNames[$itemData['item_id']] ?? null,
                'quantity'            => $itemData['quantity']       ?? 0,
                'unit'                => $itemData['unit']           ?? null,
                'price'               => $itemData['price']          ?? 0,
                'remarks'             => $itemData['item_remarks']   ?? null,
            ]);
        }
    }

    private function saveAttachments(PurchaseReturn $return, Request $request): void
    {
        if (!$request->hasFile('attachments')) return;
        foreach ($request->file('attachments') as $file) {
            $return->attachments()->create([
                'file_path'     => $file->store('purchase_returns', 'public'),
                'original_name' => $file->getClientOriginalName(),
                'file_type'     => $file->getClientMimeType(),
            ]);
        }
    }

    /**
     * Purchase return reverses the purchase:
     *   DR  Vendor (AP)              ← reduces what we owe
     *   CR  Stock in Hand (104001)   ← goods leaving inventory
     *   DR  Vendor                   ← conveyance reversed (vendor absorbs)
     *   CR  Conveyance (502001)      ← reverses conveyance expense
     *   DR  Purchase Discount (402001) ← discount given on return clears
     *   CR  Vendor                   ← discount reduces return credit
     */
    private function postPurchaseReturnEntries(PurchaseReturn $return): void
    {
        $itemsTotal = $return->items->sum(fn($i) => $i->quantity * $i->price);
        $conveyance = (float)($return->convance_charges ?? 0);
        $discount   = (float)($return->bill_discount    ?? 0);

        $this->syncVoucherEntries(
            $return,
            'purchase_return',
            $return->return_date,
            [
                [
                    'dr_id'   => $return->vendor_id,
                    'cr'      => '104001',
                    'amount'  => $itemsTotal,
                    'remarks' => 'Goods returned to vendor',
                ],
                [
                    'dr_id'   => $return->vendor_id,
                    'cr'      => '502001',
                    'amount'  => $conveyance,
                    'remarks' => 'Conveyance reversed on return',
                ],
                [
                    'dr'      => '402001',
                    'cr_id'   => $return->vendor_id,
                    'amount'  => $discount,
                    'remarks' => 'Discount on purchase return',
                ],
            ]
        );

        Log::info('[PurchaseReturn] Accounting synced', [
            'id'         => $return->id,
            'items'      => $itemsTotal,
            'conveyance' => $conveyance,
            'discount'   => $discount,
        ]);
    }
}