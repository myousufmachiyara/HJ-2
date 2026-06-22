<?php

namespace App\Http\Controllers;

use App\Models\ProductionDetail;
use App\Models\ProductCategory;
use App\Models\ChartOfAccounts;
use App\Models\ProductionReceiving;
use App\Models\Production;
use App\Models\Product;
use App\Models\MeasurementUnit;
use App\Traits\PostsAccountingEntries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProductionController extends Controller
{
    use PostsAccountingEntries;

    // ── Index ─────────────────────────────────────────────────────────

    public function index()
    {
        $productions = Production::with([
            'vendor',
            'category',
            'details',
            'receivings.details',
        ])->orderBy('id', 'desc')->get();

        return view('production.index', compact('productions'));
    }

    // ── Create ────────────────────────────────────────────────────────

    public function create()
    {
        $vendors    = ChartOfAccounts::where('account_type', 'vendor')->get();
        $categories = ProductCategory::all();
        $units      = MeasurementUnit::all();

        $allProducts = Product::select('id', 'name', 'barcode', 'measurement_unit')
            ->where('item_type', 'raw')
            ->get()
            ->map(fn($p) => (object)[
                'id'   => $p->id,
                'name' => $p->name,
                'unit' => $p->measurement_unit,
            ]);

        return view('production.create', compact('vendors', 'categories', 'allProducts', 'units'));
    }

    // ── Store ─────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $request->validate([
            'vendor_id'                   => 'required|exists:chart_of_accounts,id',
            'category_id'                 => 'nullable|exists:product_categories,id',
            'order_date'                  => 'required|date',
            'production_type'             => 'required|in:cmt,sale_leather',
            'att.*'                       => 'nullable|file|max:2048',
            'remarks'                     => 'nullable|string',
            'item_details'                => 'required|array|min:1',
            'item_details.*.product_id'   => 'required|exists:products,id',
            'item_details.*.variation_id' => 'nullable|exists:product_variations,id',
            'item_details.*.invoice_id'   => 'nullable|exists:purchase_invoices,id',
            'item_details.*.qty'          => 'required|numeric|min:0.01',
            'item_details.*.item_unit'    => 'required|exists:measurement_units,id',
            'item_details.*.item_rate'    => 'required|numeric|min:0',
            'item_details.*.desc'         => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $attachments = [];
            if ($request->hasFile('att')) {
                foreach ($request->file('att') as $file) {
                    $attachments[] = $file->store('attachments/productions', 'public');
                }
            }

            $production = Production::create([
                'vendor_id'       => $request->vendor_id,
                'category_id'     => $request->category_id,
                'order_date'      => $request->order_date,
                'production_type' => $request->production_type,
                'remarks'         => $request->remarks,
                'attachments'     => $attachments,
                'created_by'      => auth()->id(),
            ]);

            foreach ($request->item_details as $item) {
                $production->details()->create([
                    'product_id'   => $item['product_id'],
                    'variation_id' => $item['variation_id'] ?? null,
                    'invoice_id'   => $item['invoice_id']   ?? null,
                    'qty'          => $item['qty'],
                    'unit'         => $item['item_unit'],
                    'rate'         => $item['item_rate'],
                    'desc'         => $item['desc']         ?? null,
                ]);
            }

            $production->loadMissing('details');
            $this->postProductionEntries($production);

            DB::commit();
            Log::info('[Production] Created', ['id' => $production->id]);
            return redirect()->route('production.index')
                ->with('success', 'Production order PO-' . $production->id . ' created successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Production] Store failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Something went wrong: ' . $e->getMessage());
        }
    }

    // ── Edit ──────────────────────────────────────────────────────────

    public function edit($id)
    {
        $production = Production::with([
            'details.variation',
            'details.product',
            'details.invoice.vendor',
        ])->findOrFail($id);
        $vendors    = ChartOfAccounts::where('account_type', 'vendor')->get();
        $categories = ProductCategory::all();
        $units      = MeasurementUnit::all();

        $allProducts = Product::with('variations')
            ->select('id', 'name', 'barcode', 'measurement_unit')
            ->where('item_type', 'raw')
            ->get()
            ->map(fn($p) => (object)[
                'id'         => $p->id,
                'name'       => $p->name,
                'unit'       => $p->measurement_unit,
                'variations' => $p->variations->map(fn($v) => (object)[
                    'id'  => $v->id,
                    'sku' => $v->sku,
                ]),
            ]);

        return view('production.edit', compact('production', 'vendors', 'categories', 'allProducts', 'units'));
    }

    // ── Update ────────────────────────────────────────────────────────

    public function update(Request $request, $id)
    {
        $request->validate([
            'vendor_id'                   => 'required|exists:chart_of_accounts,id',
            'category_id'                 => 'nullable|exists:product_categories,id',
            'order_date'                  => 'required|date',
            'production_type'             => 'required|in:cmt,sale_leather',
            'attachments.*'               => 'nullable|file|max:2048',
            'remarks'                     => 'nullable|string',
            'item_details'                => 'required|array|min:1',
            'item_details.*.item_id'      => 'required|exists:products,id',
            'item_details.*.variation_id' => 'nullable|exists:product_variations,id',
            'item_details.*.invoice'      => 'nullable|exists:purchase_invoices,id',
            'item_details.*.qty'          => 'required|numeric|min:0.01',
            'item_details.*.item_unit'    => 'required|exists:measurement_units,id',
            'item_details.*.rate'         => 'required|numeric|min:0',
            'item_details.*.desc'         => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $production  = Production::findOrFail($id);
            $attachments = $production->attachments ?? [];

            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $attachments[] = $file->store('attachments/productions', 'public');
                }
            }

            $production->update([
                'vendor_id'       => $request->vendor_id,
                'category_id'     => $request->category_id,
                'order_date'      => $request->order_date,
                'production_type' => $request->production_type,
                'remarks'         => $request->remarks,
                'attachments'     => $attachments,
            ]);

            $production->details()->delete();
            foreach ($request->item_details as $item) {
                $production->details()->create([
                    'product_id'   => $item['item_id'],
                    'variation_id' => $item['variation_id'] ?? null,
                    'invoice_id'   => $item['invoice']      ?? null,
                    'qty'          => $item['qty'],
                    'unit'         => $item['item_unit'],
                    'rate'         => $item['rate'],
                    'desc'         => $item['desc']         ?? null,
                ]);
            }

            $production->loadMissing('details');
            $this->postProductionEntries($production);

            DB::commit();
            Log::info('[Production] Updated', ['id' => $production->id]);
            return redirect()->route('production.index')
                ->with('success', 'Production order PO-' . $production->id . ' updated successfully.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Production] Update failed', ['error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Failed to update: ' . $e->getMessage());
        }
    }

    // ── Destroy ───────────────────────────────────────────────────────

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $production = Production::findOrFail($id);
            $this->deleteVoucherEntries($production);
            $production->details()->delete();
            $production->delete();
            DB::commit();
            return redirect()->route('production.index')
                ->with('success', 'Production order deleted.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Production] Destroy failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    // ── Print (Production Order PDF) ──────────────────────────────────

    public function print($id)
    {
        $production = Production::with([
            'vendor',
            'details.product.measurementUnit',
            'details.variation',
            'details.invoice',
        ])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Jild');
        $pdf->SetAuthor('Jild');
        $pdf->SetTitle('Production Order PO-' . $production->id);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) $pdf->Image($logoPath, 10, 10, 30);

        $pdf->SetXY(130, 12);
        $pdf->writeHTML('
            <table border="1" cellpadding="4" style="font-size:10px;line-height:14px;border-collapse:collapse;">
                <tr><td><b>Order #</b></td><td>PO-' . str_pad($production->id, 4, '0', STR_PAD_LEFT) . '</td></tr>
                <tr><td><b>Date</b></td><td>' . Carbon::parse($production->order_date)->format('d/m/Y') . '</td></tr>
                <tr><td><b>Type</b></td><td>' . ucwords(str_replace('_', ' ', $production->production_type)) . '</td></tr>
                <tr><td><b>Vendor</b></td><td>' . ($production->vendor->name ?? '-') . '</td></tr>
            </table>',
        false, false, false, false, '');

        $pdf->Line(60, 52.25, 200, 52.25);
        $pdf->SetXY(10, 48);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(50, 8, 'Production Order', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        $html = '
        <table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <th width="6%">S.No</th>
                <th width="24%">Item</th>
                <th width="18%">Variation</th>
                <th width="16%">Invoice #</th>
                <th width="12%">Qty</th>
                <th width="12%">Rate</th>
                <th width="12%">Total</th>
            </tr>';

        $count = 0; $totalAmount = 0;
        foreach ($production->details as $detail) {
            $count++;
            $amount       = $detail->qty * $detail->rate;
            $totalAmount += $amount;
            $html .= '
            <tr>
                <td>' . $count . '</td>
                <td align="left">' . ($detail->product->name ?? '-') . '</td>
                <td>' . ($detail->variation->sku ?? '-') . '</td>
                <td>' . ($detail->invoice_id ? 'PUR-' . str_pad($detail->invoice_id, 5, '0', STR_PAD_LEFT) : '-') . '</td>
                <td>' . number_format($detail->qty, 2) . ' ' . ($detail->product->measurementUnit->shortcode ?? '') . '</td>
                <td align="right">' . number_format($detail->rate, 2) . '</td>
                <td align="right">' . number_format($amount, 2) . '</td>
            </tr>';
        }
        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="6" align="right"><b>Total Amount</b></td>
                <td align="right"><b>' . number_format($totalAmount, 2) . '</b></td>
            </tr>
        </table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        if (!empty($production->remarks)) {
            $pdf->writeHTML(
                '<b>Remarks:</b><br><span style="font-size:11px;">' . nl2br($production->remarks) . '</span>',
                true, false, true, false, ''
            );
        }

        $pdf->Ln(20);
        $y = $pdf->GetY();
        $pdf->Line(28, $y, 68, $y);
        $pdf->Line(130, $y, 170, $y);
        $pdf->SetXY(28,  $y + 2); $pdf->Cell(40, 6, 'Issued By',     0, 0, 'C');
        $pdf->SetXY(130, $y + 2); $pdf->Cell(40, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output('production_' . $production->id . '.pdf', 'I');
    }

    // ── Gate Pass PDF ─────────────────────────────────────────────────

    public function printGatepass($id)
    {
        $production = Production::with([
            'vendor',
            'details.product.measurementUnit',
            'details.variation',
        ])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Jild');
        $pdf->SetAuthor('Jild');
        $pdf->SetTitle('Gate Pass PO-' . $production->id);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) $pdf->Image($logoPath, 10, 10, 30);

        $pdf->SetXY(130, 12);
        $pdf->writeHTML('
            <table border="1" cellpadding="4" style="font-size:10px;line-height:14px;border-collapse:collapse;">
                <tr><td><b>Gate Pass #</b></td><td>PO-' . str_pad($production->id, 4, '0', STR_PAD_LEFT) . '</td></tr>
                <tr><td><b>Date</b></td><td>' . Carbon::parse($production->order_date)->format('d/m/Y') . '</td></tr>
                <tr><td><b>Vendor</b></td><td>' . ($production->vendor->name ?? '-') . '</td></tr>
            </table>',
        false, false, false, false, '');

        $pdf->Line(60, 52.25, 200, 52.25);
        $pdf->SetXY(10, 48);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(50, 8, 'Production Gate Pass', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        $html = '
        <table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <th width="7%">S.No</th>
                <th width="30%">Item</th>
                <th width="23%">Variation</th>
                <th width="20%">Qty</th>
                <th width="20%">Remarks</th>
            </tr>';

        $count = 0;
        foreach ($production->details as $detail) {
            $count++;
            $html .= '
            <tr>
                <td>' . $count . '</td>
                <td align="left">' . ($detail->product->name ?? '-') . '</td>
                <td>' . ($detail->variation->sku ?? '-') . '</td>
                <td>' . number_format($detail->qty, 2) . ' ' . ($detail->product->measurementUnit->shortcode ?? '') . '</td>
                <td>' . ($detail->desc ?? '-') . '</td>
            </tr>';
        }
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');

        $pdf->Ln(20);
        $y = $pdf->GetY();
        $pdf->Line(28, $y, 68, $y);
        $pdf->Line(130, $y, 170, $y);
        $pdf->SetXY(28,  $y + 2); $pdf->Cell(40, 6, 'Issued By',   0, 0, 'C');
        $pdf->SetXY(130, $y + 2); $pdf->Cell(40, 6, 'Received By', 0, 0, 'C');

        return $pdf->Output('gatepass_' . $production->id . '.pdf', 'I');
    }

    // ── Costing Summary PDF (per production) ──────────────────────────
    public function summary($id)
    {
        $production = Production::with([
            'vendor',
            'details.product.measurementUnit',
            'receivings.details.product.measurementUnit',
            'wastageReceivings.details.product.measurementUnit',
        ])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Jild');
        $pdf->SetAuthor('Jild');
        $pdf->SetTitle('Summary PO-' . $production->id);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) $pdf->Image($logoPath, 10, 10, 30);

        // ── Info Box ──────────────────────────────────────────────────────
        $pdf->SetXY(130, 12);
        $pdf->writeHTML('
            <table border="1" cellpadding="4" style="font-size:10px;line-height:14px;border-collapse:collapse;">
                <tr><td><b>Order #</b></td><td>PO-' . str_pad($production->id, 4, '0', STR_PAD_LEFT) . '</td></tr>
                <tr><td><b>Date</b></td><td>' . Carbon::parse($production->order_date)->format('d/m/Y') . '</td></tr>
                <tr><td><b>Type</b></td><td>' . ucwords(str_replace('_', ' ', $production->production_type)) . '</td></tr>
                <tr><td><b>Vendor</b></td><td>' . ($production->vendor->name ?? '-') . '</td></tr>
            </table>',
        false, false, false, false, '');

        $pdf->Line(60, 52.25, 200, 52.25);
        $pdf->SetXY(10, 48);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(55, 8, 'Production Summary', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        // ── 1. Raw Material Issued ────────────────────────────────────────
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 6, '  1. Raw Material Issued', 0, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(1);

        $totalRawGiven = 0;
        $totalRawCost  = 0;
        $count         = 0;

        $html = '
        <table border="0.3" cellpadding="3" style="text-align:center;font-size:9px;">
            <tr style="background-color:#dce6f0;font-weight:bold;">
                <th width="7%">S.No</th>
                <th width="35%" align="left">Item</th>
                <th width="20%">Qty</th>
                <th width="19%">Rate</th>
                <th width="19%">Total Cost</th>
            </tr>';

        foreach ($production->details as $detail) {
            $count++;
            $qty  = (float) $detail->qty;
            $rate = (float) $detail->rate;
            $cost = $qty * $rate;
            $totalRawGiven += $qty;
            $totalRawCost  += $cost;

            $html .= '
            <tr>
                <td>' . $count . '</td>
                <td align="left">' . ($detail->product->name ?? '-') . '</td>
                <td>' . number_format($qty, 2) . ' ' . ($detail->product->measurementUnit->shortcode ?? '') . '</td>
                <td align="right">' . number_format($rate, 2) . '</td>
                <td align="right">' . number_format($cost, 2) . '</td>
            </tr>';
        }

        $html .= '
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <td colspan="2" align="right">Total</td>
                <td>' . number_format($totalRawGiven, 2) . '</td>
                <td></td>
                <td align="right">PKR ' . number_format($totalRawCost, 2) . '</td>
            </tr>
        </table>';

        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(3);

        // ── Pre-compute wastage/extra totals (needed for consumption calc) ────
        // These must be calculated BEFORE Section 2's consumption math, since
        // actual consumption depends on raw that was actually used (not returned).
        $totalExtraReturned   = 0;
        $totalWastageWriteoff = 0;

        foreach ($production->wastageReceivings as $wr) {
            foreach ($wr->details as $wd) {
                $isExtra = ($wd->return_type ?? 'extra') === 'extra';
                if ($isExtra) {
                    $totalExtraReturned += (float) $wd->quantity;
                } else {
                    $totalWastageWriteoff += (float) $wd->quantity;
                }
            }
        }

        $totalReturned = $totalExtraReturned + $totalWastageWriteoff;

        // ── 2. Finished Goods Received ────────────────────────────────────
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 6, '  2. Finished Goods Received', 0, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(1);

        // Build FG summary — pull consumption from product.consumption column
        $productSummary        = [];
        $totalProductsReceived = 0;

        foreach ($production->receivings as $rec) {
            foreach ($rec->details as $d) {
                $pid = $d->product_id;
                if (!isset($productSummary[$pid])) {
                    $productSummary[$pid] = [
                        'name'                 => $d->product->name ?? '-',
                        'unit'                 => $d->product->measurementUnit->shortcode ?? '-',
                        'qty'                  => 0,
                        'mfg'                  => (float) ($d->manufacturing_cost ?? $d->product->manufacturing_cost ?? 0),
                        'est_consumption'      => (float) ($d->product->consumption ?? 0), // ← product.consumption
                    ];
                }
                $productSummary[$pid]['qty'] += (float) $d->received_qty;
                $totalProductsReceived       += (float) $d->received_qty;
            }
        }

        // Raw actually consumed = issued - extra returned - wastage written off
        // (raw that was returned to stock or scrapped was never "consumed" by production)
        $rawConsumedTotal = max(0, $totalRawGiven - $totalExtraReturned - $totalWastageWriteoff);

        // Actual consumption ratio = raw consumed / FG produced
        $actualConsumption = $totalProductsReceived > 0
            ? round($rawConsumedTotal / $totalProductsReceived, 4)
            : 0;

        // Raw cost per FG piece is still based on cost of raw actually consumed,
        // not the full issued amount (extra/wasted raw's cost shouldn't load onto FG cost)
        $rawCostTotal = $totalRawGiven > 0
            ? $totalRawCost * ($rawConsumedTotal / $totalRawGiven)
            : 0;

        $rawCostPerUnit = $totalProductsReceived > 0
            ? $rawCostTotal / $totalProductsReceived
            : 0;

        // Build per-product consumption alerts
        $consumptionAlerts = [];
        foreach ($productSummary as $pid => $p) {
            $est = $p['est_consumption'];
            if ($est <= 0) continue; // no estimate set on this product

            $diffPct  = round(abs($actualConsumption - $est) / $est * 100, 1);
            $overUsed = $actualConsumption > $est;

            if ($diffPct > 10) {
                $consumptionAlerts[$pid] = [
                    'product'   => $p['name'],
                    'estimated' => $est,
                    'actual'    => $actualConsumption,
                    'diff_pct'  => $diffPct,
                    'over_used' => $overUsed,
                    'severity'  => $diffPct > 25 ? 'critical' : 'warning',
                ];
            }
        }

        $grandTotalCost = 0;
        $count          = 0;

        $html = '
        <table border="0.3" cellpadding="3" style="text-align:center;font-size:9px;">
            <tr style="background-color:#dce6f0;font-weight:bold;">
                <th width="6%">S.No</th>
                <th width="20%" align="left">Product</th>
                <th width="9%">Qty</th>
                <th width="10%">Mfg/pc</th>
                <th width="10%">Raw/pc</th>
                <th width="11%">Est.Raw/pc</th>
                <th width="10%">Total/pc</th>
                <th width="9%">Var%</th>
                <th width="7%">Status</th>
                <th width="8%">Total</th>
            </tr>';

        foreach ($productSummary as $pid => $p) {
            $count++;
            $totalCostPc     = $rawCostPerUnit + $p['mfg'];
            $totalCost       = $p['qty'] * $totalCostPc;
            $grandTotalCost += $totalCost;

            $est      = $p['est_consumption'];
            $varText  = '—';
            $varStyle = 'color:#999;';
            $status   = '—';
            $statusStyle = 'color:#999;';

            if ($est > 0) {
                $diffPct  = round(abs($actualConsumption - $est) / $est * 100, 1);
                $overUsed = $actualConsumption > $est;
                $sign     = $overUsed ? '+' : '-';
                $varText  = $sign . $diffPct . '%';

                if ($diffPct <= 10) {
                    $varStyle    = 'color:#166534;';
                    $status      = 'OK';
                    $statusStyle = 'color:#166534;font-weight:bold;';
                } elseif ($diffPct <= 25) {
                    $varStyle    = 'color:#b45309;font-weight:bold;';
                    $status      = 'WARN';
                    $statusStyle = 'color:#b45309;font-weight:bold;';
                } else {
                    $varStyle    = 'color:#dc2626;font-weight:bold;';
                    $status      = 'CRIT';
                    $statusStyle = 'color:#dc2626;font-weight:bold;';
                }
            }

            $html .= '
            <tr>
                <td>' . $count . '</td>
                <td align="left">' . $p['name'] . '</td>
                <td>' . number_format($p['qty'], 2) . ' ' . $p['unit'] . '</td>
                <td align="right">' . number_format($p['mfg'], 2) . '</td>
                <td align="right">' . number_format($rawCostPerUnit, 2) . '</td>
                <td align="right">' . ($est > 0 ? number_format($est, 4) : '—') . '</td>
                <td align="right">' . number_format($totalCostPc, 2) . '</td>
                <td align="right" style="' . $varStyle . '">' . $varText . '</td>
                <td align="center" style="' . $statusStyle . '">' . $status . '</td>
                <td align="right">' . number_format($totalCost, 2) . '</td>
            </tr>';
        }

        if (empty($productSummary)) {
            $html .= '<tr><td colspan="10" align="center" style="color:#888;">No finished goods received yet</td></tr>';
        } else {
            $html .= '
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <td colspan="9" align="right">Grand Total Cost</td>
                <td align="right">PKR ' . number_format($grandTotalCost, 2) . '</td>
            </tr>';
        }

        $html .= '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(3);

        // ── 3. Raw Material Returned (split: Extra vs Wastage) ─────────────
        $nextSection = 3;

        if ($totalReturned > 0) {
            $pdf->SetFillColor(23, 54, 93);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 6, '  3. Raw Material Returned', 0, 1, 'L', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln(1);

            $html = '
            <table border="0.3" cellpadding="3" style="text-align:center;font-size:9px;">
                <tr style="background-color:#dce6f0;font-weight:bold;">
                    <th width="6%">S.No</th>
                    <th width="34%" align="left">Item</th>
                    <th width="14%">Type</th>
                    <th width="16%">Qty</th>
                    <th width="30%">Remarks</th>
                </tr>';

            $count = 0;
            foreach ($production->wastageReceivings as $wr) {
                foreach ($wr->details as $wd) {
                    $count++;
                    $isExtra   = ($wd->return_type ?? 'extra') === 'extra';
                    $typeLabel = $isExtra ? 'Extra' : 'Wastage';
                    $typeSub   = $isExtra ? '(Back to Stock)' : '(Write-off)';
                    $typeColor = $isExtra ? '#166534' : '#dc2626';

                    $html .= '
                    <tr>
                        <td>' . $count . '</td>
                        <td align="left">' . ($wd->product->name ?? '-') . '</td>
                        <td style="color:' . $typeColor . ';font-weight:bold;font-size:8px;">
                            ' . $typeLabel . '<br><span style="font-weight:normal;color:#666;">' . $typeSub . '</span>
                        </td>
                        <td>' . number_format($wd->quantity, 2) . ' '
                            . ($wd->product->measurementUnit->shortcode ?? '') . '</td>
                        <td>' . ($wd->remarks ?? '-') . '</td>
                    </tr>';
                }
            }

            $html .= '
                <tr style="background-color:#e8f4e8;font-weight:bold;">
                    <td colspan="3" align="right">Extra Returned (Back to Stock)</td>
                    <td style="color:#166534;">' . number_format($totalExtraReturned, 2) . '</td>
                    <td></td>
                </tr>
                <tr style="background-color:#fde8e8;font-weight:bold;">
                    <td colspan="3" align="right">Wastage Written-off</td>
                    <td style="color:#dc2626;">' . number_format($totalWastageWriteoff, 2) . '</td>
                    <td></td>
                </tr>
                <tr style="background-color:#f5f5f5;font-weight:bold;">
                    <td colspan="3" align="right">Total Returned</td>
                    <td>' . number_format($totalReturned, 2) . '</td>
                    <td></td>
                </tr>
            </table>';

            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Ln(3);
            $nextSection = 4;
        }

        // ── Consumption Alert Section (only if alerts exist) ──────────────
        if (!empty($consumptionAlerts)) {
            $pdf->SetFillColor(185, 28, 28);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(0, 6, '  ' . $nextSection . '. Consumption Alerts', 0, 1, 'L', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln(1);

            $html = '
            <table border="0.3" cellpadding="3" style="font-size:8.5px;">
                <tr style="background-color:#fde8e8;font-weight:bold;">
                    <th width="20%" align="left">Product</th>
                    <th width="15%" align="right">Est. Raw/pc</th>
                    <th width="15%" align="right">Actual Raw/pc</th>
                    <th width="10%" align="center">Variance</th>
                    <th width="10%" align="center">Severity</th>
                    <th width="30%" align="left">Remark</th>
                </tr>';

            foreach ($consumptionAlerts as $alert) {
                $isCritical  = $alert['severity'] === 'critical';
                $rowBg       = $isCritical
                    ? 'background-color:#fff1f1;'
                    : 'background-color:#fffbeb;';
                $sevColor    = $isCritical
                    ? 'color:#dc2626;font-weight:bold;'
                    : 'color:#b45309;font-weight:bold;';
                $sevLabel    = $isCritical ? 'CRITICAL' : 'WARNING';
                $sign        = $alert['over_used'] ? '+' : '-';
                $remark      = $alert['over_used']
                    ? 'Consumed ' . $alert['diff_pct'] . '% more than estimated. Check for loss or theft.'
                    : 'Consumed ' . $alert['diff_pct'] . '% less than estimated. Verify FG receiving records.';

                $html .= '
                <tr style="' . $rowBg . '">
                    <td>' . $alert['product'] . '</td>
                    <td align="right">' . number_format($alert['estimated'], 4) . '</td>
                    <td align="right">' . number_format($alert['actual'], 4) . '</td>
                    <td align="center" style="' . $sevColor . '">'
                        . $sign . $alert['diff_pct'] . '%</td>
                    <td align="center" style="' . $sevColor . '">' . $sevLabel . '</td>
                    <td style="font-size:8px;">' . $remark . '</td>
                </tr>';
            }

            $html .= '</table>';
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Ln(3);
            $nextSection++;
        }

        // ── Final Summary ─────────────────────────────────────────────────
        // rawConsumedTotal already computed above (Section 2)
        // rawAtVendor = raw issued - raw consumed - extra returned - wastage written off
        // (this represents raw not yet accounted for: still at vendor / not returned/recorded)
        $rawAtVendor = max(0, $totalRawGiven - $rawConsumedTotal - $totalExtraReturned - $totalWastageWriteoff);

        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 6, '  ' . $nextSection . '. Summary', 0, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(1);

        // Build estimated row per product (only those with consumption set)
        $estRows = '';
        foreach ($productSummary as $p) {
            $est = $p['est_consumption'];
            if ($est <= 0) continue;

            $diffPct     = round(abs($actualConsumption - $est) / $est * 100, 1);
            $overUsed    = $actualConsumption > $est;
            $varColor    = $diffPct <= 10
                ? '#166534'
                : ($diffPct <= 25 ? '#b45309' : '#dc2626');
            $sign        = $overUsed ? '+' : '-';

            $estRows .= '
            <tr>
                <td><b>Est. Consumption — ' . $p['name'] . '</b></td>
                <td align="right">
                    ' . number_format($est, 4) . '
                    &nbsp;<span style="color:' . $varColor . ';font-size:8px;">
                        (' . $sign . $diffPct . '% variance)
                    </span>
                </td>
            </tr>';
        }

        $html = '
        <table border="0.3" cellpadding="4" style="font-size:9px;">
            <tr style="background-color:#f0f4f8;">
                <td width="60%"><b>Total Raw Issued</b></td>
                <td width="40%" align="right">' . number_format($totalRawGiven, 2) . '</td>
            </tr>
            <tr>
                <td><b>Total Raw Cost (Issued)</b></td>
                <td align="right">PKR ' . number_format($totalRawCost, 2) . '</td>
            </tr>
            <tr style="background-color:#f0f4f8;">
                <td><b>Total FG Received</b></td>
                <td align="right">' . number_format($totalProductsReceived, 2) . ' pcs</td>
            </tr>
            <tr>
                <td><b>Raw Actually Consumed</b></td>
                <td align="right">' . number_format($rawConsumedTotal, 2) . '</td>
            </tr>
            <tr style="background-color:#f0f4f8;">
                <td><b>Actual Consumption (Raw per FG piece)</b></td>
                <td align="right">' . number_format($actualConsumption, 4) . '</td>
            </tr>
            ' . $estRows . '
            <tr style="background-color:#e8f4e8;">
                <td><b>Extra Raw Returned (Back to Stock)</b></td>
                <td align="right" style="color:#166534;">' . number_format($totalExtraReturned, 2) . '</td>
            </tr>
            <tr style="background-color:#fde8e8;">
                <td><b>Wastage Written-off</b></td>
                <td align="right" style="color:#dc2626;">' . number_format($totalWastageWriteoff, 2) . '</td>
            </tr>
            <tr>
                <td><b>Raw Still at Vendor (unaccounted)</b></td>
                <td align="right">' . number_format($rawAtVendor, 2) . '</td>
            </tr>
            <tr style="background-color:#e8f4e8;font-weight:bold;">
                <td><b>Grand Total Cost (Raw Consumed + Mfg)</b></td>
                <td align="right">PKR ' . number_format($grandTotalCost, 2) . '</td>
            </tr>
        </table>';

        $pdf->writeHTML($html, true, false, true, false, '');

        // ── Signatures ────────────────────────────────────────────────────
        $pdf->Ln(20);
        $y = $pdf->GetY();
        $pdf->Line(28, $y, 68, $y);
        $pdf->Line(130, $y, 170, $y);
        $pdf->SetXY(28,  $y + 2); $pdf->Cell(40, 6, 'Prepared By',   0, 0, 'C');
        $pdf->SetXY(130, $y + 2); $pdf->Cell(40, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output('production_summary_' . $production->id . '.pdf', 'I');
    }

    // ── API helper ────────────────────────────────────────────────────

    public function getProductProductions(Request $request, $productId)
    {
        try {
            $variationId = $request->get('variation_id');
            $query = ProductionDetail::with('production')->where('product_id', $productId);

            if ($variationId) {
                $query->where('variation_id', $variationId);
            } else {
                $query->whereNull('variation_id');
            }

            return response()->json($query->get()->map(fn($d) => [
                'id'   => $d->production_id,
                'rate' => $d->rate,
            ]));

        } catch (\Throwable $e) {
            Log::error('[Production] getProductProductions failed: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong.'], 500);
        }
    }

    // ── Accounting ────────────────────────────────────────────────────

    /**
     * CMT:          DR WIP (104003)  CR Raw Material Stock (104002)
     * Sale Leather: DR Vendor        CR Raw Material Stock (104002)
     */
    private function postProductionEntries(Production $production): void
    {
        $totalAmount = $production->details->sum(fn($d) => $d->qty * $d->rate);
        if ($totalAmount <= 0) return;

        if ($production->production_type === 'sale_leather') {
            $this->syncVoucherEntries($production, 'production', $production->order_date, [[
                'dr_id'   => $production->vendor_id,
                'cr'      => '104002',
                'amount'  => $totalAmount,
                'remarks' => 'Leather sold to manufacturer — PO-' . $production->id,
            ]]);
        } else {
            $this->syncVoucherEntries($production, 'production', $production->order_date, [[
                'dr'      => '104003',
                'cr'      => '104002',
                'amount'  => $totalAmount,
                'remarks' => 'Raw sent for CMT — PO-' . $production->id,
            ]]);
        }

        Log::info('[Production] Accounting synced', [
            'id'    => $production->id,
            'type'  => $production->production_type,
            'total' => $totalAmount,
        ]);
    }
}