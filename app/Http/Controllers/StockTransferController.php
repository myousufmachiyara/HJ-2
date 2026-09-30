<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferDetail;
use App\Services\Inventory;
use App\Traits\PostsAccountingEntries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Stock Movement between any two locations:
 *   warehouse ⇄ customer / marketplace ⇄ vendor / CMT
 *
 * Qty:        − at FROM, + at TO   (stock ledger)
 * Accounting: DR Stock @ TO   CR Stock @ FROM   at weighted-average cost
 * Type is derived: to a customer = Delivery Challan, from a customer = Return DC.
 */
class StockTransferController extends Controller
{
    use PostsAccountingEntries;

    public function index()
    {
        $transfers = StockTransfer::with(['fromLocation', 'toLocation', 'details'])
            ->orderBy('date', 'desc')->orderBy('id', 'desc')->get();

        return view('stock-transfer.index', compact('transfers'));
    }

    public function create()
    {
        return view('stock-transfer.create', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validateRequest($request);

        DB::beginTransaction();
        try {
            $transfer = StockTransfer::create([
                'type'             => $this->deriveType($data['from_location_id'], $data['to_location_id']),
                'date'             => $data['date'],
                'remarks'          => $data['remarks'] ?? null,
                'from_location_id' => $data['from_location_id'],
                'to_location_id'   => $data['to_location_id'],
                'created_by'       => Auth::id(),
            ]);

            $this->saveAndPost($transfer, $data['items']);

            DB::commit();
            return redirect()->route('stock_transfer.index')->with('success', 'Stock movement #' . $transfer->id . ' saved.');
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to store stock transfer: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to save stock movement: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $transfer = StockTransfer::with(['details.product', 'details.variation'])->findOrFail($id);
        return view('stock-transfer.edit', $this->formData() + compact('transfer'));
    }

    public function update(Request $request, $id)
    {
        $data = $this->validateRequest($request);

        DB::beginTransaction();
        try {
            $transfer = StockTransfer::findOrFail($id);
            $transfer->update([
                'type'             => $this->deriveType($data['from_location_id'], $data['to_location_id']),
                'date'             => $data['date'],
                'from_location_id' => $data['from_location_id'],
                'to_location_id'   => $data['to_location_id'],
                'remarks'          => $data['remarks'] ?? null,
            ]);

            $transfer->details()->delete();
            $this->saveAndPost($transfer, $data['items']);

            DB::commit();
            return redirect()->route('stock_transfer.index')->with('success', 'Stock movement updated.');
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to update stock transfer: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to update stock movement: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $transfer = StockTransfer::findOrFail($id);
            Inventory::clear($transfer);
            $this->deleteVoucherEntries($transfer);
            $transfer->delete();
            DB::commit();
            return redirect()->route('stock_transfer.index')->with('success', 'Stock movement deleted.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to delete stock transfer: ' . $e->getMessage());
            return back()->with('error', 'Failed to delete stock movement.');
        }
    }

    /** AJAX: qty available at a location for one item (used by movement & sale forms). */
    public function available(Request $request)
    {
        $request->validate(['location_id' => 'required|integer', 'product_id' => 'required|integer']);
        return response()->json([
            'qty' => Inventory::balance((int) $request->location_id, (int) $request->product_id,
                $request->filled('variation_id') ? (int) $request->variation_id : null),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function formData(): array
    {
        return [
            'locationGroups'    => Location::grouped(),
            'defaultLocationId' => Location::defaultId(),
            'products'          => Product::orderBy('name')->get(['id', 'name', 'barcode']),
        ];
    }

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'date'                 => 'required|date',
            'from_location_id'     => 'required|exists:locations,id',
            'to_location_id'       => 'required|exists:locations,id|different:from_location_id',
            'remarks'              => 'nullable|string|max:500',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.quantity'     => 'required|numeric|min:0.01',
        ], [
            'to_location_id.different' => 'From and To location must be different.',
        ]);
    }

    private function deriveType(int $fromId, int $toId): string
    {
        $types = Location::whereIn('id', [$fromId, $toId])->pluck('type', 'id');
        if (($types[$toId] ?? null) === Location::CUSTOMER)   return 'dc';
        if (($types[$fromId] ?? null) === Location::CUSTOMER) return 'return_dc';
        return 'transfer';
    }

    private function saveAndPost(StockTransfer $transfer, array $items): void
    {
        $lines = collect($items)->map(fn ($i) => [
            'product_id'   => (int) $i['product_id'],
            'variation_id' => !empty($i['variation_id']) ? (int) $i['variation_id'] : null,
            'qty'          => (float) $i['quantity'],
        ])->all();

        Inventory::assertAvailable($transfer->from_location_id, $lines, $transfer);

        $from  = Location::findOrFail($transfer->from_location_id);
        $to    = Location::findOrFail($transfer->to_location_id);
        $value = 0.0;
        $rows  = [];

        foreach ($lines as $l) {
            $cost = Inventory::avgCost($l['product_id'], $l['variation_id'], $transfer->date);
            StockTransferDetail::create([
                'transfer_id'  => $transfer->id,
                'product_id'   => $l['product_id'],
                'variation_id' => $l['variation_id'],
                'quantity'     => $l['qty'],
                'unit_cost'    => $cost,
            ]);
            $value += $cost * $l['qty'];

            $base = ['product_id' => $l['product_id'], 'variation_id' => $l['variation_id'], 'unit_cost' => $cost,
                     'remarks' => 'Movement #' . $transfer->id . ' ' . $from->name . ' → ' . $to->name];
            $rows[] = $base + ['location_id' => $from->id, 'qty' => -$l['qty']];
            $rows[] = $base + ['location_id' => $to->id,   'qty' =>  $l['qty']];
        }

        Inventory::sync($transfer, $transfer->date, $rows);

        $this->syncVoucherEntries($transfer, 'stock_transfer', $transfer->date, [[
            'dr_id'   => $to->inventoryAccountId(),
            'cr_id'   => $from->inventoryAccountId(),
            'amount'  => round($value, 2),
            'remarks' => 'Stock moved ' . $from->name . ' → ' . $to->name . ' (#' . $transfer->id . ')',
        ]]);
    }

    // Print stock transfer / delivery challan PDF
    public function print($id)
    {
        try {
            $transfer = StockTransfer::with(['fromLocation', 'toLocation', 'details.product', 'details.variation'])
                ->findOrFail($id);

            // Label switches based on the transfer type.
            $docLabel = match ($transfer->type) {
                'dc'        => 'Delivery Challan',
                'return_dc' => 'Return Delivery Challan',
                default     => 'Stock Transfer',
            };
            // "#" caption in the info table (Challan # for DCs, Transfer # otherwise).
            $refCaption = in_array($transfer->type, ['dc', 'return_dc'], true) ? 'Challan #' : 'Transfer #';

            $pdf = new \TCPDF();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetCreator('Your App');
            $pdf->SetAuthor('Your Company');
            $pdf->SetTitle($docLabel . ' #' . $transfer->id);
            $pdf->SetMargins(10, 10, 10);
            $pdf->AddPage();
            $pdf->setCellPadding(1.5);

            $logoPath = public_path('assets/img/hj-logo.jpg');
            if (file_exists($logoPath)) $pdf->Image($logoPath, 5, 11, 50);

            $pdf->SetXY(120, 12);
            $transferInfo = '
            <table cellpadding="4" border="1" style="font-size:10px; line-height:14px; border-collapse:collapse;">
                <tr>
                    <td><b>' . $refCaption . '</b></td>
                    <td>' . $transfer->id . '</td>
                </tr>
                <tr>
                    <td><b>Date</b></td>
                    <td>' . \Carbon\Carbon::parse($transfer->date)->format('d/m/Y') . '</td>
                </tr>
                <tr>
                    <td><b>From</b></td>
                    <td>' . ($transfer->fromLocation->name ?? '-') . '</td>
                </tr>
                <tr>
                    <td><b>To</b></td>
                    <td>' . ($transfer->toLocation->name ?? '-') . '</td>
                </tr>
            </table>';

            $pdf->writeHTML($transferInfo, false, false, false, false, '');

            $pdf->Line(60, 52.25, 200, 52.25);

            $pdf->SetXY(10, 48);
            $pdf->SetFillColor(23, 54, 93);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', '', 12);
            $pdf->Cell(60, 8, $docLabel, 0, 1, 'C', 1);
            $pdf->SetTextColor(0, 0, 0);

            $pdf->Ln(5);
            $html = '<table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
                <tr style="background-color:#f5f5f5; font-weight:bold;">
                    <th width="8%">S.No</th>
                    <th width="18%">Code</th>
                    <th width="20%">Name</th>
                    <th width="38%">Variation</th>
                    <th width="16%">Quantity</th>
                </tr>';

            $count = 0;
            foreach ($transfer->details as $item) {
                $count++;
                $product = $item->product; // main product
                $variation = $item->variation; // may be null
                $unit = $product->measurementUnit->shortcode ?? '-';

                $html .= '
                <tr>
                    <td align="center">' . $count . '</td>
                    <td>' . ($variation->barcode ?? $product->barcode ?? '-') . '</td>
                    <td>' . ($product->name ?? '-') . '</td>
                    <td>' . ($variation->sku ?? '-') . '</td>
                    <td align="center">' .number_format($item->quantity, 2).' '.$unit.'</td>
                </tr>';
            }
            $html .= '</table>';

            $pdf->writeHTML($html, true, false, true, false, '');

            $pdf->Ln(20);
            $yPos = $pdf->GetY();
            $lineWidth = 40;
            $pdf->Line(28, $yPos, 28 + $lineWidth, $yPos);
            $pdf->Line(130, $yPos, 130 + $lineWidth, $yPos);
            $pdf->SetXY(28, $yPos + 2);
            $pdf->Cell($lineWidth, 6, 'Authorized By', 0, 0, 'C');
            $pdf->SetXY(130, $yPos + 2);
            $pdf->Cell($lineWidth, 6, 'Received By', 0, 0, 'C');

            $fileName = strtolower(str_replace(' ', '_', $docLabel)) . '_' . $transfer->id . '.pdf';
            return $pdf->Output($fileName, 'I');
        } catch (\Exception $e) {
            Log::error('Failed to print stock transfer: '.$e->getMessage());
            return back()->with('error', 'Failed to generate stock transfer PDF.');
        }
    }
}