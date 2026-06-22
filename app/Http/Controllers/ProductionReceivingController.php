<?php

namespace App\Http\Controllers;

use App\Models\ProductionReceiving;
use App\Models\ProductionReceivingDetail;
use App\Models\Production;
use App\Models\Product;
use App\Models\ChartOfAccounts;
use App\Traits\PostsAccountingEntries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProductionReceivingController extends Controller
{
    use PostsAccountingEntries;

    public function index()
    {
        $receivings = ProductionReceiving::with(['vendor', 'production', 'details'])
            ->orderBy('id', 'desc')->get()
            ->map(function ($r) {
                $r->total_amount = $r->details->sum(fn($d) => $d->manufacturing_cost * $d->received_qty);
                return $r;
            });
        return view('production-receiving.index', compact('receivings'));
    }

    public function create(Request $request)
    {
        $productions          = Production::with('vendor')->orderBy('id', 'desc')->get();
        $products             = Product::orderBy('name')->where('item_type','fg')->get();
        $accounts             = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $selectedProductionId = $request->query('id');

        return view('production-receiving.create', compact('productions', 'products', 'selectedProductionId', 'accounts'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'production_id'                     => 'nullable|exists:productions,id',
            'vendor_id'                         => 'required|exists:chart_of_accounts,id',
            'rec_date'                          => 'required|date',
            'item_details'                      => 'required|array|min:1',
            'item_details.*.product_id'         => 'required|exists:products,id',
            'item_details.*.variation_id'       => 'nullable|exists:product_variations,id',
            'item_details.*.received_qty'       => 'required|numeric|min:0.01',
            'item_details.*.manufacturing_cost' => 'required|numeric|min:0',
            'item_details.*.remarks'            => 'nullable|string',
            'convance_charges'                  => 'required|numeric|min:0',
            'bill_discount'                     => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $grn_no = 'GRN-' . str_pad(ProductionReceiving::withTrashed()->count() + 1, 5, '0', STR_PAD_LEFT);

            $receiving = ProductionReceiving::create([
                'production_id'    => $validated['production_id'] ?? null,
                'vendor_id'        => $validated['vendor_id'],
                'rec_date'         => $validated['rec_date'],
                'grn_no'           => $grn_no,
                'convance_charges' => $validated['convance_charges'],
                'bill_discount'    => $validated['bill_discount'],
                'received_by'      => auth()->id(),
            ]);

            foreach ($validated['item_details'] as $detail) {
                ProductionReceivingDetail::create([
                    'production_receiving_id' => $receiving->id,
                    'product_id'              => $detail['product_id'],
                    'variation_id'            => $detail['variation_id'] ?? null,
                    'manufacturing_cost'      => $detail['manufacturing_cost'],
                    'received_qty'            => $detail['received_qty'],
                    'remarks'                 => $detail['remarks'] ?? null,
                ]);
            }

            $receiving->load('details');
            $this->postProductionReceivingEntries($receiving);

            DB::commit();
            Log::info('[ProdReceiving] Created', ['id' => $receiving->id]);
            return redirect()->route('production_receiving.index')->with('success', 'Production receiving created successfully!');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[ProdReceiving] Store failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to save receiving: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $receiving   = ProductionReceiving::with(['details.product', 'details.variation'])->findOrFail($id);
        $productions = Production::with('vendor')->orderBy('id', 'desc')->get();
        $products    = Product::orderBy('name')->get();
        $accounts    = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        return view('production-receiving.edit', compact('receiving', 'productions', 'products', 'accounts'));
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'production_id'                     => 'nullable|exists:productions,id',
            'vendor_id'                         => 'required|exists:chart_of_accounts,id',
            'rec_date'                          => 'required|date',
            'item_details'                      => 'required|array|min:1',
            'item_details.*.product_id'         => 'required|exists:products,id',
            'item_details.*.variation_id'       => 'nullable|exists:product_variations,id',
            'item_details.*.received_qty'       => 'required|numeric|min:0.01',
            'item_details.*.manufacturing_cost' => 'required|numeric|min:0',
            'item_details.*.remarks'            => 'nullable|string',
            'convance_charges'                  => 'required|numeric|min:0',
            'bill_discount'                     => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $receiving = ProductionReceiving::findOrFail($id);
            $receiving->update([
                'production_id'    => $validated['production_id'] ?? null,
                'vendor_id'        => $validated['vendor_id'],
                'rec_date'         => $validated['rec_date'],
                'convance_charges' => $validated['convance_charges'],
                'bill_discount'    => $validated['bill_discount'],
            ]);

            $receiving->details()->delete();
            $detailData = [];
            foreach ($validated['item_details'] as $detail) {
                $detailData[] = [
                    'production_receiving_id' => $receiving->id,
                    'product_id'              => $detail['product_id'],
                    'variation_id'            => $detail['variation_id'] ?? null,
                    'manufacturing_cost'      => $detail['manufacturing_cost'],
                    'received_qty'            => $detail['received_qty'],
                    'remarks'                 => $detail['remarks'] ?? null,
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ];
            }
            ProductionReceivingDetail::insert($detailData);

            $receiving->load('details');
            $this->postProductionReceivingEntries($receiving);

            DB::commit();
            Log::info('[ProdReceiving] Updated', ['id' => $id]);
            return redirect()->route('production_receiving.index')->with('success', 'Production receiving updated successfully!');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[ProdReceiving] Update failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to update receiving: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $receiving = ProductionReceiving::findOrFail($id);
            $this->deleteVoucherEntries($receiving);
            $receiving->details()->delete();
            $receiving->delete();
            DB::commit();
            return back()->with('success', 'Production receiving deleted.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    public function print($id)
    {
        $receiving = ProductionReceiving::with([
            'vendor',
            'production.vendor',
            'details.product.measurementUnit',
            'details.variation',
        ])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Jild');
        $pdf->SetAuthor('Jild');
        $pdf->SetTitle('Production Receiving #' . $receiving->id);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 10, 10, 30);
        }

        $pdf->SetXY(130, 12);
        $pdf->writeHTML('
            <table border="1" cellpadding="4" style="font-size:10px;line-height:14px;border-collapse:collapse;">
                <tr><td><b>GRN #</b></td><td>' . ($receiving->grn_no ?? $receiving->id) . '</td></tr>
                <tr><td><b>Date</b></td><td>' . Carbon::parse($receiving->rec_date)->format('d/m/Y') . '</td></tr>
                <tr><td><b>Production #</b></td><td>' . ($receiving->production_id ? '#' . $receiving->production_id : '-') . '</td></tr>
                <tr><td><b>Vendor</b></td><td>' . ($receiving->vendor->name ?? $receiving->production?->vendor->name ?? '-') . '</td></tr>
            </table>',
        false, false, false, false, '');

        $pdf->Line(60, 52.25, 200, 52.25);

        $pdf->SetXY(10, 48);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(55, 8, 'Production Receiving', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        $html = '
        <table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <th width="6%">S.No</th>
                <th width="24%">Item</th>
                <th width="22%">Variation</th>
                <th width="12%">M.Cost</th>
                <th width="12%">Qty</th>
                <th width="12%">Total</th>
                <th width="12%">Remarks</th>
            </tr>';

        $count      = 0;
        $grandTotal = 0;

        foreach ($receiving->details as $detail) {
            $count++;
            $rowTotal    = $detail->manufacturing_cost * $detail->received_qty;
            $grandTotal += $rowTotal;

            $html .= '
            <tr>
                <td>' . $count . '</td>
                <td>' . ($detail->product->name ?? '-') . '</td>
                <td>' . ($detail->variation->sku ?? '-') . '</td>
                <td align="right">' . number_format($detail->manufacturing_cost, 2) . '</td>
                <td>' . number_format($detail->received_qty, 2) . ' ' . ($detail->product->measurementUnit->shortcode ?? '') . '</td>
                <td align="right">' . number_format($rowTotal, 2) . '</td>
                <td>' . ($detail->remarks ?? '-') . '</td>
            </tr>';
        }

        $conveyance = (float)($receiving->convance_charges ?? 0);
        $discount   = (float)($receiving->bill_discount    ?? 0);
        $net        = $grandTotal + $conveyance - $discount;

        $html .= '<tr><td colspan="5" align="right"><b>Sub Total</b></td><td align="right"><b>' . number_format($grandTotal, 2) . '</b></td><td></td></tr>';

        if ($conveyance > 0) {
            $html .= '<tr><td colspan="5" align="right">Conveyance</td><td align="right">' . number_format($conveyance, 2) . '</td><td></td></tr>';
        }
        if ($discount > 0) {
            $html .= '<tr><td colspan="5" align="right">Discount</td><td align="right">(' . number_format($discount, 2) . ')</td><td></td></tr>';
        }

        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="5" align="right"><b>Net Total</b></td>
                <td align="right"><b>' . number_format($net, 2) . '</b></td>
                <td></td>
            </tr>
        </table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        $pdf->Ln(20);
        $y = $pdf->GetY();
        $pdf->Line(28,  $y, 68,  $y);
        $pdf->Line(130, $y, 170, $y);
        $pdf->SetXY(28,  $y + 2); $pdf->Cell(40, 6, 'Received By',   0, 0, 'C');
        $pdf->SetXY(130, $y + 2); $pdf->Cell(40, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output('production_receiving_' . $receiving->id . '.pdf', 'I');
    }

    // ── Accounting ────────────────────────────────────────────────────

    /**
     * DR  Finished Goods Stock (104004)  CR Vendor  ← mfg cost payable
     * DR  Conveyance Expense (502001)    CR Vendor  ← conveyance
     * DR  Vendor                         CR Purchase Discount (402001)
     */
    private function postProductionReceivingEntries(ProductionReceiving $receiving): void
    {
        $itemsTotal = $receiving->details->sum(fn($d) => $d->manufacturing_cost * $d->received_qty);
        $conveyance = (float)($receiving->convance_charges ?? 0);
        $discount   = (float)($receiving->bill_discount    ?? 0);

        $this->syncVoucherEntries(
            $receiving,
            'production_receiving',
            $receiving->rec_date,
            [
                ['dr' => '104004', 'cr_id' => $receiving->vendor_id, 'amount' => $itemsTotal, 'remarks' => 'Finished goods received'],
                ['dr' => '502001', 'cr_id' => $receiving->vendor_id, 'amount' => $conveyance,  'remarks' => 'Conveyance charges'],
                ['dr_id' => $receiving->vendor_id, 'cr' => '402001', 'amount' => $discount,    'remarks' => 'Discount received'],
            ]
        );

        Log::info('[ProdReceiving] Accounting synced', [
            'receiving_id' => $receiving->id,
            'items_total'  => $itemsTotal,
            'conveyance'   => $conveyance,
            'discount'     => $discount,
        ]);
    }
}