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
 * Staff only enter: date, vendor, item, variation, qty. The CMT does not tell us
 * fabric, PANNA or consumption, so receiving is PLAIN STOCK-IN at the default
 * warehouse: no vendor bill, no fabric minus, no voucher. Cost stays 0 and the
 * rows are "unbilled" until a CMT Bill (Purchase → CMT Bill) picks them up and
 * posts the CMT amount, fabric consumption and the FG cost.
 */
class ProductionReceivingController extends Controller
{
    use PostsAccountingEntries;

    public function index()
    {
        $receivings = ProductionReceiving::with(['vendor', 'details', 'attachments'])
            ->orderBy('id', 'desc')->get()
            ->map(function ($r) {
                $r->total_amount = $r->details->sum(fn ($d) => $d->manufacturing_cost * $d->received_qty);
                $r->bill_state   = $r->details->contains(fn ($d) => $d->cmt_bill_id) ? 'billed'
                                 : ($r->details->contains(fn ($d) => $d->legacy_billed) ? 'old' : 'pending');
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
        \App\Support\EntryDate::assertNew($request->input('rec_date'), 'rec_date');   // no back dates (except super admin)
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

            $this->saveDetails($receiving, $validated['item_details']);
            $this->post($receiving->fresh('details'));
            \App\Support\Attachments::saveTo($receiving->attachments(), $request, 'production_receivings');

            DB::commit();
            Log::info('[FGReceiving] Created', ['id' => $receiving->id]);

            return $this->redirectWithNotes($receiving, 'saved');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[FGReceiving] Store failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to save receiving: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $receiving = ProductionReceiving::with(['details.product', 'details.variation', 'attachments'])->findOrFail($id);
        if ($msg = $this->lockedReason($receiving)) {
            return redirect()->route('production_receiving.index')->with('error', $msg);
        }
        return view('production-receiving.edit', $this->formData() + compact('receiving'));
    }

    public function update(Request $request, $id)
    {
        \App\Support\EntryDate::assertEditable(\App\Models\ProductionReceiving::find($id), 'rec_date', $request->input('rec_date'), 'rec_date');
        $validated = $this->validateRequest($request);

        DB::beginTransaction();
        try {
            $receiving = ProductionReceiving::findOrFail($id);
            if ($msg = $this->lockedReason($receiving)) throw new \RuntimeException($msg);
            // production_id / conveyance / discount are kept as they are (not on the simplified form)
            $receiving->update([
                'vendor_id'   => $validated['vendor_id'],
                'rec_date'    => $validated['rec_date'],
                'location_id' => $receiving->location_id ?? Location::defaultId(),
            ]);

            $receiving->details()->delete();
            $this->saveDetails($receiving, $validated['item_details']);
            $this->post($receiving->fresh('details'));
            \App\Support\Attachments::removeFrom($receiving->attachments(), $request);
            \App\Support\Attachments::saveTo($receiving->attachments(), $request, 'production_receivings');

            DB::commit();
            Log::info('[FGReceiving] Updated', ['id' => $id]);

            return $this->redirectWithNotes($receiving, 'updated');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[FGReceiving] Update failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to update receiving: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        \App\Support\EntryDate::assertEditable(\App\Models\ProductionReceiving::find($id), 'rec_date');
        DB::beginTransaction();
        try {
            $receiving = ProductionReceiving::findOrFail($id);
            if ($receiving->details()->whereNotNull('cmt_bill_id')->exists()) {
                throw new \RuntimeException('This GRN is already on a CMT Bill. Delete that CMT Bill first.');
            }
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

    /** GRNs that are on a CMT Bill, or were billed the old way, cannot be edited. */
    private function lockedReason(ProductionReceiving $receiving): ?string
    {
        if ($receiving->details()->whereNotNull('cmt_bill_id')->exists()) {
            return $receiving->grn_no . ' is already on a CMT Bill. Delete that CMT Bill first to change it.';
        }
        if ($receiving->details()->where('legacy_billed', true)->exists()) {
            return $receiving->grn_no . ' was billed the old way (before CMT Bill) and cannot be edited.';
        }
        return null;
    }

    private function redirectWithNotes(ProductionReceiving $receiving, string $verb)
    {
        return redirect()->route('production_receiving.index')
            ->with('success', 'Receiving ' . $receiving->grn_no . ' ' . $verb . '. Bill it from Purchase → CMT Bill.');
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
        return $request->validate([
            'vendor_id'                   => 'required|exists:chart_of_accounts,id',
            'rec_date'                    => 'required|date',
            'item_details'                => 'required|array|min:1',
            'item_details.*.product_id'   => 'required|exists:products,id',
            'item_details.*.variation_id' => 'nullable|exists:product_variations,id',
            'item_details.*.received_qty' => 'required|numeric|min:0.01',
            'attachments.*'               => \App\Support\Attachments::RULE,
        ]);
    }

    private function nextGrnNo(): string
    {
        $last = ProductionReceiving::withTrashed()->where('grn_no', 'like', 'GRN-%')
            ->lockForUpdate()->pluck('grn_no')
            ->map(fn ($n) => (int) substr($n, 4))->max() ?? 0;

        return 'GRN-' . str_pad($last + 1, 5, '0', STR_PAD_LEFT);
    }

    /** Lines are SKU + qty only; cost and fabric are filled in by the CMT Bill. */
    private function saveDetails(ProductionReceiving $receiving, array $lines): void
    {
        foreach ($lines as $line) {
            ProductionReceivingDetail::create([
                'production_receiving_id' => $receiving->id,
                'product_id'              => (int) $line['product_id'],
                'variation_id'            => !empty($line['variation_id']) ? (int) $line['variation_id'] : null,
                'fabric_qty'              => 0,
                'fabric_rate'             => 0,
                'manufacturing_cost'      => 0,
                'received_qty'            => (float) $line['received_qty'],
                'legacy_billed'           => false,
            ]);
        }
    }

    /** Plain stock-in at the warehouse (cost 0 until the CMT Bill). No vouchers. */
    private function post(ProductionReceiving $receiving): void
    {
        $warehouse = Location::find($receiving->location_id) ?? Location::default();
        if ((int) $receiving->location_id !== $warehouse->id) {
            $receiving->location_id = $warehouse->id;
            $receiving->saveQuietly();
        }

        $ledger = [];
        foreach ($receiving->details as $d) {
            $ledger[] = [
                'product_id'   => $d->product_id,
                'variation_id' => $d->variation_id,
                'location_id'  => $warehouse->id,
                'qty'          => (float) $d->received_qty,
                'unit_cost'    => 0,
                'remarks'      => 'FG received ' . $receiving->grn_no,
            ];
        }

        $this->deleteVoucherEntries($receiving);
        Inventory::sync($receiving, $receiving->rec_date, $ledger);

        Log::info('[FGReceiving] Posted', ['id' => $receiving->id]);
    }
}
