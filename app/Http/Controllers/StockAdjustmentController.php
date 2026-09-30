<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use App\Services\Inventory;
use App\Traits\PostsAccountingEntries;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stock Adjustment / Opening Stock, per location.
 *
 *  opening    : opening qty at a location (warehouse, marketplace, fabric at CMT)
 *               DR Stock @ location   CR Opening Stock Equity (301010)
 *  count      : physical count — enter the counted qty; the difference to the
 *               software qty (as of the date) is posted
 *  adjustment : manual +/− qty (damage, lost, found)
 *               excess:   DR Stock @ location           CR Stock Adjustment (502010)
 *               shortage: DR Stock Adjustment (502010)   CR Stock @ location
 */
class StockAdjustmentController extends Controller
{
    use PostsAccountingEntries;

    public function index()
    {
        $adjustments = StockAdjustment::with(['location', 'items'])->latest('date')->latest('id')->get();
        return view('stock-adjustments.index', compact('adjustments'));
    }

    public function create()
    {
        return view('stock-adjustments.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validateRequest($request);

        DB::beginTransaction();
        try {
            $adj = StockAdjustment::create([
                'adj_no'      => StockAdjustment::nextNo(),
                'date'        => $data['date'],
                'location_id' => $data['location_id'],
                'type'        => $data['type'],
                'remarks'     => $data['remarks'] ?? null,
                'created_by'  => auth()->id(),
            ]);
            $this->saveAndPost($adj, $data['items']);

            DB::commit();
            return redirect()->route('stock_adjustments.index')->with('success', 'Stock adjustment ' . $adj->adj_no . ' saved.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[StockAdjustment] Store failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to save: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        return redirect()->route('stock_adjustments.edit', $id);
    }

    public function edit($id)
    {
        $adjustment = StockAdjustment::with(['items.product', 'items.variation'])->findOrFail($id);
        return view('stock-adjustments.edit', $this->formData() + compact('adjustment'));
    }

    public function update(Request $request, $id)
    {
        $data = $this->validateRequest($request);

        DB::beginTransaction();
        try {
            $adj = StockAdjustment::findOrFail($id);
            $adj->update([
                'date'        => $data['date'],
                'location_id' => $data['location_id'],
                'type'        => $data['type'],
                'remarks'     => $data['remarks'] ?? null,
            ]);
            $adj->items()->delete();
            $this->saveAndPost($adj, $data['items']);

            DB::commit();
            return redirect()->route('stock_adjustments.index')->with('success', 'Stock adjustment ' . $adj->adj_no . ' updated.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[StockAdjustment] Update failed: ' . $e->getMessage());
            return back()->withInput()->with('error', 'Failed to update: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $adj = StockAdjustment::findOrFail($id);
            Inventory::clear($adj);
            $this->deleteVoucherEntries($adj);
            $adj->delete();
            DB::commit();
            return redirect()->route('stock_adjustments.index')->with('success', 'Stock adjustment deleted.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    public function print($id)
    {
        $adj = StockAdjustment::with(['location', 'items.product', 'items.variation'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetTitle($adj->adj_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) $pdf->Image($logoPath, 5, 11, 50);

        $pdf->SetXY(120, 12);
        $pdf->writeHTML('<table border="1" cellpadding="4" style="font-size:10px;">
            <tr><td><b>Adj #</b></td><td>' . e($adj->adj_no) . '</td></tr>
            <tr><td><b>Date</b></td><td>' . Carbon::parse($adj->date)->format('d/m/Y') . '</td></tr>
            <tr><td><b>Location</b></td><td>' . e($adj->location->name ?? '-') . '</td></tr>
            <tr><td><b>Type</b></td><td>' . e($adj->typeLabel()) . '</td></tr></table>', false, false, false, false, '');

        $pdf->SetXY(10, 52);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(60, 8, 'Stock Adjustment', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);

        $isCount = $adj->type === 'count';
        $html = '<table border="0.3" cellpadding="4" style="font-size:9px;text-align:center;"><tr style="background-color:#f5f5f5;font-weight:bold;">
            <th width="6%">#</th><th width="30%">Item</th><th width="20%">Variation</th>'
            . ($isCount ? '<th width="11%">System</th><th width="11%">Counted</th>' : '<th width="22%"></th>')
            . '<th width="11%">Adj. Qty</th><th width="11%">Value</th></tr>';
        $total = 0;
        foreach ($adj->items as $i => $it) {
            $val = $it->quantity * $it->unit_cost;
            $total += $val;
            $html .= '<tr><td>' . ($i + 1) . '</td><td>' . e($it->product->name ?? '-') . '</td><td>' . e($it->variation->sku ?? '-') . '</td>'
                . ($isCount ? '<td>' . number_format($it->system_qty, 2) . '</td><td>' . number_format($it->counted_qty, 2) . '</td>' : '<td></td>')
                . '<td>' . number_format($it->quantity, 2) . '</td><td align="right">' . number_format($val, 2) . '</td></tr>';
        }
        $html .= '<tr><td colspan="' . ($isCount ? 6 : 5) . '" align="right"><b>Total</b></td><td align="right"><b>' . number_format($total, 2) . '</b></td></tr></table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        if ($adj->remarks) $pdf->writeHTML('<b>Remarks:</b> ' . e($adj->remarks), true, false, true, false, '');

        return $pdf->Output($adj->adj_no . '.pdf', 'I');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function formData(): array
    {
        return [
            'locationGroups'    => Location::grouped(),
            'defaultLocationId' => Location::defaultId(),
            'products'          => Product::orderBy('name')->get(['id', 'name', 'item_type']),
            'types'             => StockAdjustment::TYPES,
        ];
    }

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'date'                 => 'required|date',
            'location_id'          => 'required|exists:locations,id',
            'type'                 => 'required|in:' . implode(',', array_keys(StockAdjustment::TYPES)),
            'remarks'              => 'nullable|string|max:1000',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.quantity'     => 'required|numeric',
            'items.*.unit_cost'    => 'nullable|numeric|min:0',
        ]);
    }

    private function saveAndPost(StockAdjustment $adj, array $items): void
    {
        $location = Location::findOrFail($adj->location_id);
        $date     = Carbon::parse($adj->date)->toDateString();
        $rows     = [];
        $gain     = 0.0; // value added
        $loss     = 0.0; // value removed

        // merge duplicate lines (same item scanned twice)
        $merged = [];
        foreach ($items as $it) {
            $key = $it['product_id'] . '-' . ($it['variation_id'] ?? '');
            if (isset($merged[$key])) {
                $merged[$key]['quantity'] += (float) $it['quantity'];
            } else {
                $merged[$key] = $it + ['quantity' => 0];
                $merged[$key]['quantity'] = (float) $it['quantity'];
            }
        }

        foreach ($merged as $it) {
            $pid = (int) $it['product_id'];
            $vid = !empty($it['variation_id']) ? (int) $it['variation_id'] : null;
            $entered = (float) $it['quantity'];

            $systemQty  = 0;
            $countedQty = null;
            if ($adj->type === 'count') {
                $systemQty  = Inventory::balance($location->id, $pid, $vid, $adj, $date);
                $countedQty = $entered;
                $qty        = round($countedQty - $systemQty, 3);
            } elseif ($adj->type === 'opening') {
                $qty = abs($entered);
            } else {
                $qty = $entered;
            }

            $cost = isset($it['unit_cost']) && $it['unit_cost'] !== '' && (float) $it['unit_cost'] > 0 && $adj->type === 'opening'
                ? (float) $it['unit_cost']
                : Inventory::avgCost($pid, $vid, $date, $adj);

            StockAdjustmentItem::create([
                'stock_adjustment_id' => $adj->id,
                'product_id'          => $pid,
                'variation_id'        => $vid,
                'system_qty'          => $systemQty,
                'counted_qty'         => $countedQty,
                'quantity'            => $qty,
                'unit_cost'           => $cost,
            ]);

            if ($qty == 0.0) continue;

            $rows[] = [
                'product_id' => $pid, 'variation_id' => $vid, 'location_id' => $location->id,
                'qty' => $qty, 'unit_cost' => $cost, 'remarks' => $adj->typeLabel() . ' ' . $adj->adj_no,
            ];
            $qty > 0 ? $gain += $qty * $cost : $loss += abs($qty) * $cost;
        }

        Inventory::sync($adj, $date, $rows);

        $stockAcc = $location->inventoryAccountId();
        $counter  = $adj->type === 'opening' ? '301010' : '502010';
        $this->syncVoucherEntries($adj, 'stock_adjustment', $date, [
            ['dr_id' => $stockAcc, 'cr' => $counter, 'amount' => round($gain, 2),
             'remarks' => $adj->typeLabel() . ' ' . $adj->adj_no . ' — ' . $location->name],
            ['dr' => $counter, 'cr_id' => $stockAcc, 'amount' => round($loss, 2),
             'remarks' => $adj->typeLabel() . ' (shortage) ' . $adj->adj_no . ' — ' . $location->name],
        ]);
    }
}
