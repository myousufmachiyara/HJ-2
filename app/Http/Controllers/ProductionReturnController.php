<?php

namespace App\Http\Controllers;

use App\Models\ProductionReturn;
use App\Models\ProductionReturnItem;
use App\Models\Production;
use App\Models\Product;
use App\Models\ChartOfAccounts;
use App\Models\MeasurementUnit;
use App\Traits\PostsAccountingEntries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProductionReturnController extends Controller
{
    use PostsAccountingEntries;

    public function index()
    {
        $returns = ProductionReturn::with(['vendor', 'items'])
            ->latest()->get()
            ->map(function ($r) {
                $r->total_amount = $r->items->sum(fn($i) => $i->quantity * $i->price);
                return $r;
            });
        return view('production-return.index', compact('returns'));
    }

    public function create()
    {
        $productions = Production::with('vendor')->get();
        $products    = Product::all();
        $vendors     = ChartOfAccounts::where('account_type', 'vendor')->get();
        $units       = MeasurementUnit::all();
        return view('production-return.create', compact('productions', 'products', 'vendors', 'units'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'vendor_id'             => 'required|exists:chart_of_accounts,id',
            'return_date'           => 'required|date',
            'remarks'               => 'nullable|string|max:1000',
            'items.*.item_id'       => 'required|exists:products,id',
            'items.*.variation_id'  => 'nullable|exists:product_variations,id',
            'items.*.production_id' => 'nullable|exists:productions,id',
            'items.*.quantity'      => 'required|numeric|min:0',
            'items.*.unit'          => 'required|exists:measurement_units,id',
            'items.*.price'         => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $return = ProductionReturn::create([
                'vendor_id'   => $request->vendor_id,
                'return_date' => $request->return_date,
                'remarks'     => $request->remarks,
                'created_by'  => auth()->id(),
            ]);

            foreach ($request->items as $item) {
                ProductionReturnItem::create([
                    'production_return_id' => $return->id,
                    'product_id'           => $item['item_id'],
                    'variation_id'         => $item['variation_id'] ?? null,
                    'production_id'        => $item['production_id'] ?? null,
                    'quantity'             => $item['quantity'],
                    'unit_id'              => $item['unit'],
                    'price'                => $item['price'],
                ]);
            }

            $return->load('items');
            $this->postProductionReturnEntries($return);

            DB::commit();
            Log::info('[ProdReturn] Created', ['id' => $return->id]);
            return redirect()->route('production_return.index')->with('success', 'Production Return saved successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[ProdReturn] Store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->withErrors(['error' => 'Failed to save: ' . $e->getMessage()]);
        }
    }

    public function edit($id)
    {
        $return   = ProductionReturn::with('items')->findOrFail($id);
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->get();
        $products = Product::with('variations')->get();
        $units    = MeasurementUnit::all();
        return view('production-return.edit', compact('return', 'vendors', 'products', 'units'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'vendor_id'             => 'required|exists:chart_of_accounts,id',
            'return_date'           => 'required|date',
            'remarks'               => 'nullable|string|max:1000',
            'items.*.item_id'       => 'required|exists:products,id',
            'items.*.variation_id'  => 'nullable|exists:product_variations,id',
            'items.*.production_id' => 'nullable|exists:productions,id',
            'items.*.quantity'      => 'required|numeric|min:0',
            'items.*.unit'          => 'required|exists:measurement_units,id',
            'items.*.price'         => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $return = ProductionReturn::findOrFail($id);
            $return->update([
                'vendor_id'   => $request->vendor_id,
                'return_date' => $request->return_date,
                'remarks'     => $request->remarks,
                'updated_by'  => auth()->id(),
            ]);

            $return->items()->delete();
            foreach ($request->items as $item) {
                ProductionReturnItem::create([
                    'production_return_id' => $return->id,
                    'product_id'           => $item['item_id'],
                    'variation_id'         => $item['variation_id'] ?? null,
                    'production_id'        => $item['production_id'] ?? null,
                    'quantity'             => $item['quantity'],
                    'unit_id'              => $item['unit'],
                    'price'                => $item['price'],
                ]);
            }

            $return->load('items');
            $this->postProductionReturnEntries($return);

            DB::commit();
            Log::info('[ProdReturn] Updated', ['id' => $return->id]);
            return redirect()->route('production_return.index')->with('success', 'Production Return updated successfully.');

        } catch (\Illuminate\Validation\ValidationException $ve) {
            throw $ve;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[ProdReturn] Update failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->withErrors(['error' => 'Failed to update: ' . $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $return = ProductionReturn::findOrFail($id);
            $this->deleteVoucherEntries($return);
            $return->items()->delete();
            $return->delete();
            DB::commit();
            return back()->with('success', 'Production Return deleted.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Delete failed: ' . $e->getMessage());
        }
    }

public function print($id)
{
    $return = ProductionReturn::with([
        'vendor',
        'items.product.measurementUnit',
        'items.variation',
        'items.unit',
    ])->findOrFail($id);

    $pdf = new \TCPDF();
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetCreator('Jild');
    $pdf->SetAuthor('Jild');
    $pdf->SetTitle('Production Return #' . $return->id);
    $pdf->SetMargins(10, 10, 10);
    $pdf->AddPage();
    $pdf->setCellPadding(1.5);

    $logoPath = public_path('assets/img/hj-logo.jpg');
    if (file_exists($logoPath)) $pdf->Image($logoPath, 5, 11, 50);


    $pdf->SetXY(130, 12);
    $pdf->writeHTML('
        <table border="1" cellpadding="4" style="font-size:10px;line-height:14px;border-collapse:collapse;">
            <tr><td><b>Return #</b></td><td>' . $return->id . '</td></tr>
            <tr><td><b>Date</b></td><td>' . Carbon::parse($return->return_date)->format('d/m/Y') . '</td></tr>
            <tr><td><b>Vendor</b></td><td>' . ($return->vendor->name ?? '-') . '</td></tr>
        </table>',
    false, false, false, false, '');

    $pdf->Line(60, 52.25, 200, 52.25);

    $pdf->SetXY(10, 48);
    $pdf->SetFillColor(23, 54, 93);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(50, 8, 'Production Return', 0, 1, 'C', 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(5);

    $html = '
    <table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
        <tr style="background-color:#f5f5f5;font-weight:bold;">
            <th width="6%">S.No</th>
            <th width="22%">Item</th>
            <th width="20%">Variation</th>
            <th width="14%">Production #</th>
            <th width="14%">Qty</th>
            <th width="12%">Rate</th>
            <th width="12%">Amount</th>
        </tr>';

    $count       = 0;
    $totalAmount = 0;

    foreach ($return->items as $item) {
        $count++;
        $amount       = $item->quantity * $item->price;
        $totalAmount += $amount;

        $html .= '
        <tr>
            <td>' . $count . '</td>
            <td>' . ($item->product->name ?? '-') . '</td>
            <td>' . ($item->variation->sku ?? '-') . '</td>
            <td>' . ($item->production_id ? '#' . $item->production_id : '-') . '</td>
            <td>' . number_format($item->quantity, 2) . ' ' . ($item->unit->shortcode ?? '') . '</td>
            <td align="right">' . number_format($item->price, 2) . '</td>
            <td align="right">' . number_format($amount, 2) . '</td>
        </tr>';
    }

    $html .= '
        <tr style="background-color:#f5f5f5;">
            <td colspan="6" align="right"><b>Total</b></td>
            <td align="right"><b>' . number_format($totalAmount, 2) . '</b></td>
        </tr>
    </table>';

    $pdf->writeHTML($html, true, false, true, false, '');

    if (!empty($return->remarks)) {
        $pdf->writeHTML(
            '<b>Remarks:</b><br><span style="font-size:12px;">' . nl2br($return->remarks) . '</span>',
            true, false, true, false, ''
        );
    }

    $pdf->Ln(20);
    $y = $pdf->GetY();
    $pdf->Line(28,  $y, 68,  $y);
    $pdf->Line(130, $y, 170, $y);
    $pdf->SetXY(28,  $y + 2); $pdf->Cell(40, 6, 'Returned By',   0, 0, 'C');
    $pdf->SetXY(130, $y + 2); $pdf->Cell(40, 6, 'Authorized By', 0, 0, 'C');

    return $pdf->Output('production_return_' . $return->id . '.pdf', 'I');
}

    // ── Accounting ────────────────────────────────────────────────────

    /**
     * Defective finished goods returned to vendor:
     *   DR  Vendor                          ← reduces vendor payable
     *   CR  Finished Goods Stock (104004)   ← goods leave inventory
     */
    private function postProductionReturnEntries(ProductionReturn $return): void
    {
        $totalAmount = $return->items->sum(fn($i) => $i->quantity * $i->price);

        if ($totalAmount <= 0) return;

        $this->syncVoucherEntries(
            $return,
            'production_return',
            $return->return_date,
            [
                [
                    'dr_id'   => $return->vendor_id,
                    'cr'      => '104004',
                    'amount'  => $totalAmount,
                    'remarks' => 'Defective goods returned to vendor',
                ],
            ]
        );

        Log::info('[ProdReturn] Accounting synced', [
            'return_id' => $return->id,
            'total'     => $totalAmount,
        ]);
    }
}