<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductionReceiving;
use App\Models\ProductionReceivingDetail;
use App\Services\Inventory;
use App\Traits\PostsAccountingEntries;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finished Goods Receiving (GRN) from a CMT vendor.
 *
 * Staff only enter: date, vendor, item, variation, qty.
 * The system then (behind the scenes):
 *   - values each line at the product's CMT cost × qty       → CMT vendor bill
 *   - consumes fabric (+ panna) at that vendor: qty × consumption from the fabric's article setup
 *   - adds the FG qty to the default warehouse
 *
 * Accounting
 *   DR Stock @ Warehouse   CR CMT Vendor              (CMT / making charges)
 *   DR Stock @ Warehouse   CR Stock @ Vendor (fabric)  (fabric moved into FG cost)
 */
class ProductionReceivingController extends Controller
{
    use PostsAccountingEntries;

    public function index()
    {
        $receivings = ProductionReceiving::with(['vendor', 'details'])
            ->orderBy('id', 'desc')->get()
            ->map(function ($r) {
                $r->total_amount = $r->details->sum(fn ($d) => $d->manufacturing_cost * $d->received_qty);
                return $r;
            });

        return view('production-receiving.index', compact('receivings'));
    }

    public function create()
    {
        return view('production-receiving.create', $this->formData());
    }

    public function store(Request $request)
    {
        $validated = $this->validateRequest($request);

        DB::beginTransaction();
        try {
            $receiving = ProductionReceiving::create([
                'vendor_id'        => $validated['vendor_id'],
                'location_id'      => Location::defaultId(),
                'rec_date'         => $validated['rec_date'],
                'grn_no'           => $this->nextGrnNo(),
                'convance_charges' => 0,
                'bill_discount'    => 0,
                'received_by'      => auth()->id(),
            ]);

            $missingCost = $this->saveDetails($receiving, $validated['item_details']);
            $this->post($receiving->fresh('details'));

            DB::commit();
            Log::info('[FGReceiving] Created', ['id' => $receiving->id]);

            return $this->redirectWithNotes($receiving, 'saved', $missingCost);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[FGReceiving] Store failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to save receiving: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $receiving = ProductionReceiving::with(['details.product', 'details.variation'])->findOrFail($id);
        return view('production-receiving.edit', $this->formData() + compact('receiving'));
    }

    public function update(Request $request, $id)
    {
        $validated = $this->validateRequest($request);

        DB::beginTransaction();
        try {
            $receiving = ProductionReceiving::findOrFail($id);
            // production_id / conveyance / discount are kept as they are (not on the simplified form)
            $receiving->update([
                'vendor_id'   => $validated['vendor_id'],
                'rec_date'    => $validated['rec_date'],
                'location_id' => $receiving->location_id ?? Location::defaultId(),
            ]);

            $receiving->details()->delete();
            $missingCost = $this->saveDetails($receiving, $validated['item_details']);
            $this->post($receiving->fresh('details'));

            DB::commit();
            Log::info('[FGReceiving] Updated', ['id' => $id]);

            return $this->redirectWithNotes($receiving, 'updated', $missingCost);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[FGReceiving] Update failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to update receiving: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $receiving = ProductionReceiving::findOrFail($id);
            $this->deleteVoucherEntries($receiving);
            Inventory::clear($receiving);
            $receiving->details()->delete();
            $receiving->delete();
            DB::commit();
            return back()->with('success', 'Receiving deleted.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    /** GRN print — quantities only (no costing), as used by warehouse staff. */
    public function print($id)
    {
        $receiving = ProductionReceiving::with([
            'vendor', 'details.product.measurementUnit', 'details.variation',
        ])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('HJ');
        $pdf->SetAuthor('HJ');
        $pdf->SetTitle('GRN ' . $receiving->grn_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) $pdf->Image($logoPath, 5, 11, 50);

        $pdf->SetXY(130, 12);
        $pdf->writeHTML('
            <table border="1" cellpadding="4" style="font-size:10px;line-height:14px;border-collapse:collapse;">
                <tr><td><b>GRN #</b></td><td>' . e($receiving->grn_no) . '</td></tr>
                <tr><td><b>Date</b></td><td>' . Carbon::parse($receiving->rec_date)->format('d/m/Y') . '</td></tr>
                <tr><td><b>Vendor</b></td><td>' . e($receiving->vendor->name ?? '-') . '</td></tr>
            </table>', false, false, false, false, '');

        $pdf->Line(60, 52.25, 200, 52.25);
        $pdf->SetXY(10, 48);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(55, 8, 'Goods Receiving Note', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        $html = '<table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5;font-weight:bold;">
                <th width="8%">S.No</th><th width="42%">Item</th><th width="30%">Variation</th><th width="20%">Qty</th>
            </tr>';
        $total = 0;
        foreach ($receiving->details as $i => $d) {
            $total += $d->received_qty;
            $html .= '<tr><td>' . ($i + 1) . '</td><td>' . e($d->product->name ?? '-') . '</td><td>' . e($d->variation->sku ?? '-') . '</td>'
                . '<td>' . number_format($d->received_qty, 2) . ' ' . e($d->product->measurementUnit->shortcode ?? '') . '</td></tr>';
        }
        $html .= '<tr style="background-color:#f5f5f5;"><td colspan="3" align="right"><b>Total Pcs</b></td><td><b>' . number_format($total, 2) . '</b></td></tr></table>';
        $pdf->writeHTML($html, true, false, true, false, '');

        $pdf->Ln(20);
        $y = $pdf->GetY();
        $pdf->Line(28, $y, 68, $y);
        $pdf->Line(130, $y, 170, $y);
        $pdf->SetXY(28, $y + 2);  $pdf->Cell(40, 6, 'Received By', 0, 0, 'C');
        $pdf->SetXY(130, $y + 2); $pdf->Cell(40, 6, 'Delivered By', 0, 0, 'C');

        return $pdf->Output('GRN_' . $receiving->grn_no . '.pdf', 'I');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function redirectWithNotes(ProductionReceiving $receiving, string $verb, array $missingCost)
    {
        $notes = [];
        if ($missingCost) {
            $notes[] = 'CMT cost is not set on: ' . implode(', ', $missingCost) . ' — set it in Products and re-save this GRN so the vendor bill is correct.';
        }
        foreach ($this->fabricWarnings($receiving) as $w) {
            $notes[] = 'Fabric over-use: ' . $w . '.';
        }
        $redirect = redirect()->route('production_receiving.index')->with('success', 'Receiving ' . $receiving->grn_no . ' ' . $verb . '.');
        return $notes ? $redirect->with('error', implode("\n", $notes)) : $redirect;
    }

    private function formData(): array
    {
        return [
            'products' => Product::where('item_type', 'fg')->orderBy('name')->get(['id', 'name', 'barcode', 'sku']),
            'accounts' => ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function validateRequest(Request $request): array
    {
        $data = $request->validate([
            'vendor_id'                   => 'required|exists:chart_of_accounts,id',
            'rec_date'                    => 'required|date',
            'item_details'                => 'required|array|min:1',
            'item_details.*.product_id'   => 'required|exists:products,id',
            'item_details.*.variation_id' => 'nullable|exists:product_variations,id',
            'item_details.*.received_qty' => 'required|numeric|min:0.01',
            'item_details.*.fabric_choice' => 'nullable|string|max:50',
        ]);

        // The CMT vendor tells us which fabric PANNA was used — required for every
        // article that has fabric set up, and it must be one of that article's options.
        $errors = [];
        foreach ($data['item_details'] as $i => $line) {
            $options = \App\Models\FabricArticle::optionsFor((int) $line['product_id'], !empty($line['variation_id']) ? (int) $line['variation_id'] : null);
            if ($options->isEmpty()) continue;

            $choice = (string) ($line['fabric_choice'] ?? '');
            $valid  = $options->contains(fn ($o) => $o->fabric_id . ':' . ($o->fabric_variation_id ?? '') === $choice);
            if (!$valid) {
                $name = Product::whereKey($line['product_id'])->value('name');
                $errors["item_details.$i.fabric_choice"] = 'Line ' . ($i + 1) . ': select the PANNA the CMT used for ' . $name . '.';
            }
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        return $data;
    }

    private function nextGrnNo(): string
    {
        $last = ProductionReceiving::withTrashed()->where('grn_no', 'like', 'GRN-%')
            ->lockForUpdate()->pluck('grn_no')
            ->map(fn ($n) => (int) substr($n, 4))->max() ?? 0;

        return 'GRN-' . str_pad($last + 1, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Save lines with the product's CMT cost and the fabric it consumes.
     *
     * Fabric + PANNA: the CMT vendor tells us which PANNA was used; staff select it
     * on the line (fabric_choice "fabricId:pannaId"), limited to the options set up
     * for that article on the fabric. Consumption comes from that setup.
     * Returns names of products that have no CMT cost set.
     */
    private function saveDetails(ProductionReceiving $receiving, array $lines): array
    {
        $products  = Product::whereIn('id', collect($lines)->pluck('product_id'))->get()->keyBy('id');
        $vendorLoc = Location::forAccount($receiving->vendor_id)
            ?? Location::syncForAccount(ChartOfAccounts::findOrFail($receiving->vendor_id));
        $missing   = [];

        foreach ($lines as $line) {
            $product = $products[$line['product_id']];
            $vid     = !empty($line['variation_id']) ? (int) $line['variation_id'] : null;
            $qty     = (float) $line['received_qty'];

            [$fabricId, $fabricVid, $perPiece] = $this->resolveFabric($product, $vid, $line['fabric_choice'] ?? null, $vendorLoc, $receiving);

            $fabricQty  = $fabricId ? round($qty * $perPiece, 3) : 0;
            $fabricRate = $fabricId ? Inventory::avgCost($fabricId, $fabricVid, $receiving->rec_date, $receiving) : 0;

            if ((float) $product->cmt_cost <= 0) $missing[] = $product->name;

            ProductionReceivingDetail::create([
                'production_receiving_id' => $receiving->id,
                'product_id'              => $product->id,
                'variation_id'            => $vid,
                'fabric_id'               => $fabricId,
                'fabric_variation_id'     => $fabricVid,
                'fabric_qty'              => $fabricQty,
                'fabric_rate'             => $fabricRate,
                'manufacturing_cost'      => (float) $product->cmt_cost,
                'received_qty'            => $qty,
            ]);
        }

        return array_values(array_unique($missing));
    }

    /** @return array{0:?int,1:?int,2:float} fabric id, panna (variation) id, consumption per piece */
    private function resolveFabric(Product $product, ?int $vid, ?string $choice, ?Location $vendorLoc, ProductionReceiving $receiving): array
    {
        $options = \App\Models\FabricArticle::optionsFor($product->id, $vid);

        if ($options->isNotEmpty()) {
            $picked = null;
            if ($choice) {
                [$f, $fv] = array_pad(explode(':', $choice, 2), 2, '');
                $picked = $options->first(fn ($o) => (string) $o->fabric_id === $f && (string) ($o->fabric_variation_id ?? '') === $fv);
            }
            // validateRequest() guarantees a valid choice; first option only as a safety net
            $picked ??= $options->first();
            return [(int) $picked->fabric_id, $picked->fabric_variation_id ? (int) $picked->fabric_variation_id : null, (float) $picked->consumption];
        }

        // legacy: fabric + consumption stored on the product itself
        if ($product->fabric_id && (float) $product->consumption > 0) {
            return [(int) $product->fabric_id, null, (float) $product->consumption];
        }

        return [null, null, 0.0];
    }

    /** Fabric lines that would leave the CMT vendor below zero → warning text. */
    private function fabricWarnings(ProductionReceiving $receiving): array
    {
        $vendorLoc = Location::forAccount($receiving->vendor_id);
        if (!$vendorLoc) return [];

        return $receiving->details()->whereNotNull('fabric_id')
            ->get()->unique(fn ($d) => $d->fabric_id . '-' . $d->fabric_variation_id)
            ->map(function ($d) use ($vendorLoc) {
                $bal = Inventory::balance($vendorLoc->id, $d->fabric_id, $d->fabric_variation_id);
                return $bal < -0.0005
                    ? Inventory::itemLabel($d->fabric_id, $d->fabric_variation_id) . ' at ' . $vendorLoc->name . ' is now '
                      . number_format($bal, 2) . ' (received pieces need ' . number_format(abs($bal), 2) . ' more than was issued)'
                    : null;
            })->filter()->values()->all();
    }

    private function post(ProductionReceiving $receiving): void
    {
        $warehouse = Location::find($receiving->location_id) ?? Location::default();
        if ((int) $receiving->location_id !== $warehouse->id) {
            $receiving->location_id = $warehouse->id;
            $receiving->saveQuietly();
        }
        $vendorLoc = Location::forAccount($receiving->vendor_id)
            ?? Location::syncForAccount(ChartOfAccounts::findOrFail($receiving->vendor_id));

        $cmtTotal    = 0.0;
        $fabricTotal = 0.0;
        $ledger      = [];

        foreach ($receiving->details as $d) {
            $qty        = (float) $d->received_qty;
            $cmt        = (float) $d->manufacturing_cost;
            $fabricCost = (float) $d->fabric_qty * (float) $d->fabric_rate;

            $cmtTotal    += $cmt * $qty;
            $fabricTotal += $fabricCost;

            $ledger[] = [
                'product_id'   => $d->product_id,
                'variation_id' => $d->variation_id,
                'location_id'  => $warehouse->id,
                'qty'          => $qty,
                'unit_cost'    => $qty > 0 ? $cmt + $fabricCost / $qty : $cmt,
                'remarks'      => 'FG received ' . $receiving->grn_no,
            ];

            if ($d->fabric_id && $vendorLoc && (float) $d->fabric_qty > 0) {
                $ledger[] = [
                    'product_id'   => $d->fabric_id,
                    'variation_id' => $d->fabric_variation_id,
                    'location_id'  => $vendorLoc->id,
                    'qty'          => -1 * (float) $d->fabric_qty,
                    'unit_cost'    => (float) $d->fabric_rate,
                    'remarks'      => 'Fabric consumed ' . $receiving->grn_no,
                ];
            }
        }

        $conveyance = (float) ($receiving->convance_charges ?? 0);
        $discount   = (float) ($receiving->bill_discount ?? 0);
        $whAcc      = $warehouse->inventoryAccountId();

        $entries = [
            ['dr_id' => $whAcc, 'cr_id' => $receiving->vendor_id, 'amount' => round($cmtTotal, 2), 'remarks' => 'CMT charges — ' . $receiving->grn_no],
            ['dr' => '502001', 'cr_id' => $receiving->vendor_id, 'amount' => $conveyance, 'remarks' => 'Conveyance — ' . $receiving->grn_no],
            ['dr_id' => $receiving->vendor_id, 'cr' => '402001', 'amount' => $discount, 'remarks' => 'Discount — ' . $receiving->grn_no],
        ];
        if ($vendorLoc) {
            $entries[] = ['dr_id' => $whAcc, 'cr_id' => $vendorLoc->inventoryAccountId(), 'amount' => round($fabricTotal, 2),
                          'remarks' => 'Fabric consumed at ' . $vendorLoc->name . ' — ' . $receiving->grn_no];
        }

        $this->syncVoucherEntries($receiving, 'production_receiving', $receiving->rec_date, $entries);
        Inventory::sync($receiving, $receiving->rec_date, $ledger);

        Log::info('[FGReceiving] Posted', ['id' => $receiving->id, 'cmt' => $cmtTotal, 'fabric' => $fabricTotal]);
    }
}
