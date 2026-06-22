<?php

namespace App\Http\Controllers;

use App\Models\ProductionWastageReceiving;
use App\Models\ProductionWastageReceivingDetail;
use App\Models\Production;
use App\Models\Product;
use App\Models\ChartOfAccounts;
use App\Models\MeasurementUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProductionWastageController extends Controller
{
    public function index()
    {
        $wastages = ProductionWastageReceiving::with(['vendor', 'production', 'details'])
            ->orderBy('id', 'desc')
            ->get();

        return view('production-wastage.index', compact('wastages'));
    }

    public function create(Request $request)
    {
        $productions  = Production::with('vendor')->orderBy('id', 'desc')->get();
        $products     = Product::where('item_type', 'raw')->orderBy('name')->get();
        $vendors      = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $units        = MeasurementUnit::all();
        $selectedProductionId = $request->query('id');

        return view('production-wastage.create', compact(
            'productions', 'products', 'vendors', 'units', 'selectedProductionId'
        ));
    }

    public function store(Request $request)
    {
        $request->validate([
            'production_id'              => 'nullable|exists:productions,id',
            'vendor_id'                  => 'required|exists:chart_of_accounts,id',
            'rec_date'                   => 'required|date',
            'remarks'                    => 'nullable|string',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'required|exists:products,id',
            'items.*.variation_id'       => 'nullable|exists:product_variations,id',
            'items.*.unit_id'            => 'required|exists:measurement_units,id',
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.remarks'            => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $grn_no = 'WRN-' . str_pad(
                ProductionWastageReceiving::withTrashed()->count() + 1,
                5, '0', STR_PAD_LEFT
            );

            $wastage = ProductionWastageReceiving::create([
                'production_id' => $request->production_id ?? null,
                'vendor_id'     => $request->vendor_id,
                'rec_date'      => $request->rec_date,
                'grn_no'        => $grn_no,
                'remarks'       => $request->remarks,
                'received_by'   => auth()->id(),
            ]);

            foreach ($request->items as $item) {
                ProductionWastageReceivingDetail::create([
                    'wastage_receiving_id' => $wastage->id,
                    'product_id'           => $item['product_id'],
                    'variation_id'         => $item['variation_id'] ?? null,
                    'unit_id'              => $item['unit_id'],
                    'quantity'             => $item['quantity'],
                    'return_type'          => $item['return_type'],   // ← add
                    'remarks'              => $item['remarks'] ?? null,
                ]);
            }

            DB::commit();
            Log::info('[Wastage] Created', ['id' => $wastage->id]);
            return redirect()->route('production_wastage.index')
                ->with('success', 'Wastage return ' . $grn_no . ' saved successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Wastage] Store failed: ' . $e->getMessage());
            return back()->withInput()->with('error', 'Failed to save: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $wastage      = ProductionWastageReceiving::with(['details.product', 'details.variation', 'details.unit'])->findOrFail($id);
        $productions  = Production::with('vendor')->orderBy('id', 'desc')->get();
        $products     = Product::where('item_type', 'raw')->orderBy('name')->get();
        $vendors      = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get();
        $units        = MeasurementUnit::all();

        return view('production-wastage.edit', compact('wastage', 'productions', 'products', 'vendors', 'units'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'production_id'              => 'nullable|exists:productions,id',
            'vendor_id'                  => 'required|exists:chart_of_accounts,id',
            'rec_date'                   => 'required|date',
            'remarks'                    => 'nullable|string',
            'items'                      => 'required|array|min:1',
            'items.*.product_id'         => 'required|exists:products,id',
            'items.*.variation_id'       => 'nullable|exists:product_variations,id',
            'items.*.unit_id'            => 'required|exists:measurement_units,id',
            'items.*.quantity'           => 'required|numeric|min:0.01',
            'items.*.remarks'            => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $wastage = ProductionWastageReceiving::findOrFail($id);
            $wastage->update([
                'production_id' => $request->production_id ?? null,
                'vendor_id'     => $request->vendor_id,
                'rec_date'      => $request->rec_date,
                'remarks'       => $request->remarks,
            ]);

            $wastage->details()->delete();
            foreach ($request->items as $item) {
                ProductionWastageReceivingDetail::create([
                    'wastage_receiving_id' => $wastage->id,
                    'product_id'           => $item['product_id'],
                    'variation_id'         => $item['variation_id'] ?? null,
                    'unit_id'              => $item['unit_id'],
                    'quantity'             => $item['quantity'],
                    'return_type'          => $item['return_type'],   // ← add
                    'remarks'              => $item['remarks'] ?? null,
                ]);
            }

            DB::commit();
            Log::info('[Wastage] Updated', ['id' => $id]);
            return redirect()->route('production_wastage.index')
                ->with('success', 'Wastage return updated successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Wastage] Update failed: ' . $e->getMessage());
            return back()->withInput()->with('error', 'Failed to update: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $wastage = ProductionWastageReceiving::findOrFail($id);
            $wastage->details()->delete();
            $wastage->delete();
            DB::commit();
            return back()->with('success', 'Wastage return deleted.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    public function print($id)
    {
        $wastage = ProductionWastageReceiving::with([
            'vendor',
            'production',
            'details.product.measurementUnit',
            'details.variation',
            'details.unit',
        ])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Jild');
        $pdf->SetAuthor('Jild');
        $pdf->SetTitle('Wastage Return ' . $wastage->grn_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) $pdf->Image($logoPath, 10, 10, 30);

        // Totals for summary box
        $totalExtra   = $wastage->details->where('return_type', 'extra')->sum('quantity');
        $totalWastage = $wastage->details->where('return_type', 'wastage')->sum('quantity');

        $pdf->SetXY(130, 12);
        $pdf->writeHTML('
            <table border="1" cellpadding="4" style="font-size:10px;line-height:14px;border-collapse:collapse;">
                <tr><td><b>WRN #</b></td><td>' . $wastage->grn_no . '</td></tr>
                <tr><td><b>Date</b></td><td>' . Carbon::parse($wastage->rec_date)->format('d/m/Y') . '</td></tr>
                <tr><td><b>Production #</b></td><td>' . ($wastage->production_id ? 'PO-' . $wastage->production_id : '-') . '</td></tr>
                <tr><td><b>Vendor</b></td><td>' . ($wastage->vendor->name ?? '-') . '</td></tr>
                <tr><td><b>Extra (Stock)</b></td><td>' . number_format($totalExtra, 3) . '</td></tr>
                <tr><td><b>Wastage (W/O)</b></td><td>' . number_format($totalWastage, 3) . '</td></tr>
            </table>',
        false, false, false, false, '');

        $pdf->Line(60, 52.25, 200, 52.25);
        $pdf->SetXY(10, 48);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(55, 8, 'Raw Material Wastage Return', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        $html = '
        <table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <th width="5%">S.No</th>
                <th width="28%">Raw Material</th>
                <th width="16%">Variation</th>
                <th width="13%">Type</th>
                <th width="11%">Qty</th>
                <th width="8%">Unit</th>
                <th width="19%">Remarks</th>
            </tr>';

        $count    = 0;
        $sumExtra = 0;
        $sumWaste = 0;

        foreach ($wastage->details as $detail) {
            $count++;
            $isExtra     = ($detail->return_type ?? 'extra') === 'extra';
            $typeLabel   = $isExtra ? 'Extra'   : 'Wastage';
            $typeColor   = $isExtra ? '#166534' : '#dc2626';
            $typeSub     = $isExtra ? '(Back to Stock)' : '(Write-off)';

            if ($isExtra) $sumExtra += $detail->quantity;
            else          $sumWaste += $detail->quantity;

            $html .= '
            <tr>
                <td>' . $count . '</td>
                <td align="left">' . ($detail->product->name ?? '-') . '</td>
                <td>' . ($detail->variation->sku ?? '-') . '</td>
                <td style="color:' . $typeColor . ';font-weight:bold;font-size:9px;">
                    ' . $typeLabel . '<br><span style="font-weight:normal;color:#666;">' . $typeSub . '</span>
                </td>
                <td>' . number_format($detail->quantity, 3) . '</td>
                <td>' . ($detail->unit->shortcode ?? '-') . '</td>
                <td>' . ($detail->remarks ?? '-') . '</td>
            </tr>';
        }

        $html .= '
            <tr style="background-color:#e8f4e8;font-weight:bold;">
                <td colspan="3" align="right">Extra Returned (Back to Stock)</td>
                <td></td>
                <td style="color:#166534;">' . number_format($sumExtra, 3) . '</td>
                <td colspan="2"></td>
            </tr>
            <tr style="background-color:#fde8e8;font-weight:bold;">
                <td colspan="3" align="right">Wastage (Write-off)</td>
                <td></td>
                <td style="color:#dc2626;">' . number_format($sumWaste, 3) . '</td>
                <td colspan="2"></td>
            </tr>
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <td colspan="3" align="right">Total Returned</td>
                <td></td>
                <td>' . number_format($sumExtra + $sumWaste, 3) . '</td>
                <td colspan="2"></td>
            </tr>
        </table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        if (!empty($wastage->remarks)) {
            $pdf->writeHTML(
                '<b>Remarks:</b><br><span style="font-size:11px;">' . nl2br($wastage->remarks) . '</span>',
                true, false, true, false, ''
            );
        }

        $pdf->Ln(20);
        $y = $pdf->GetY();
        $pdf->Line(28, $y, 68, $y);
        $pdf->Line(130, $y, 170, $y);
        $pdf->SetXY(28,  $y + 2); $pdf->Cell(40, 6, 'Received By',   0, 0, 'C');
        $pdf->SetXY(130, $y + 2); $pdf->Cell(40, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output('wastage_' . $wastage->grn_no . '.pdf', 'I');
    }
}