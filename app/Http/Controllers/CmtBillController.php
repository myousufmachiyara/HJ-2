<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\CmtBill;
use App\Models\CmtBillLine;
use App\Models\Location;
use App\Models\ProductCategory;
use App\Models\ProductionReceivingDetail;
use App\Models\StockLedger;
use App\Services\Inventory;
use App\Traits\PostsAccountingEntries;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * CMT Bill (Purchase → CMT Bill).
 *
 * FG Receiving is plain stock-in. This screen bills the CMT for a date range:
 * it lists the vendor's UNBILLED received pieces grouped by product category;
 * the user types the rate per category (and optionally the fabric + meters per
 * piece). On save:
 *
 *   DR Stock @ Warehouse   CR CMT Vendor             qty × rate
 *   DR Stock @ Warehouse   CR Stock @ Vendor (fabric)  fabric qty × avg cost   (only if fabric given)
 *   stock:  fabric − at the vendor location;  FG unit cost = rate + meters × fabric cost
 *
 * Every received row can be billed once (details.cmt_bill_id); deleting the bill releases them.
 */
class CmtBillController extends Controller
{
    use PostsAccountingEntries;

    public function index()
    {
        $bills = CmtBill::with('vendor')->orderByDesc('id')->get();
        return view('cmt-bill.index', compact('bills'));
    }

    public function create(Request $request)
    {
        $vendors  = ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get(['id', 'name']);
        $vendorId = (int) $request->query('vendor_id');
        $from     = $request->query('date_from');
        $to       = $request->query('date_to');

        $groups = collect();
        $fabrics = collect();
        $total   = 0;
        if ($vendorId && $from && $to) {
            $groups  = $this->candidateGroups($vendorId, $from, $to);
            $fabrics = $this->vendorFabrics($vendorId);
            $rates   = $this->lastRates($vendorId);
            $groups->each(function ($g) use ($rates) { $g->rate = $rates[$g->category_id ?? 0] ?? ''; });
            $total = $groups->sum('qty');
        }

        return view('cmt-bill.create', compact('vendors', 'vendorId', 'from', 'to', 'groups', 'fabrics', 'total'));
    }

    public function store(Request $request)
    {
        \App\Support\EntryDate::assertNew($request->input('bill_date'), 'bill_date');
        $data = $request->validate([
            'vendor_id'              => 'required|exists:chart_of_accounts,id',
            'date_from'              => 'required|date',
            'date_to'                => 'required|date|after_or_equal:date_from',
            'bill_date'              => 'required|date',
            'remarks'                => 'nullable|string|max:500',
            'lines'                  => 'required|array|min:1',
            'lines.*.category_id'    => 'nullable|integer',
            'lines.*.rate'           => 'required|numeric|min:0.01',
            'lines.*.fabric_choice'  => 'nullable|string|max:50',
            'lines.*.meters'         => 'nullable|numeric|min:0',
        ], ['lines.*.rate.required' => 'Enter a rate for every category.', 'lines.*.rate.min' => 'Rate must be greater than zero.']);

        DB::beginTransaction();
        try {
            $vendorId = (int) $data['vendor_id'];
            $rows = ProductionReceivingDetail::query()
                ->join('production_receivings as r', 'r.id', '=', 'production_receiving_details.production_receiving_id')
                ->join('products as p', 'p.id', '=', 'production_receiving_details.product_id')
                ->whereNull('r.deleted_at')
                ->where('r.vendor_id', $vendorId)
                ->whereBetween('r.rec_date', [$data['date_from'], $data['date_to']])
                ->whereNull('production_receiving_details.cmt_bill_id')
                ->where('production_receiving_details.legacy_billed', false)
                ->lockForUpdate()
                ->get(['production_receiving_details.*', 'p.category_id as cat_id', 'r.id as rec_id']);

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Nothing to bill for this vendor and date range (already billed or no receivings).']);
            }

            $input = collect($data['lines'])->keyBy(fn ($l) => (int) ($l['category_id'] ?? 0));
            foreach ($rows->groupBy(fn ($r) => (int) ($r->cat_id ?? 0))->keys() as $catKey) {
                if (!$input->has($catKey)) {
                    throw ValidationException::withMessages(['lines' => 'A category is missing in the bill. Reload the page and try again.']);
                }
            }

            $warehouse = Location::default();
            $vendorLoc = Location::forAccount($vendorId) ?? Location::syncForAccount(ChartOfAccounts::findOrFail($vendorId));

            $bill = CmtBill::create([
                'bill_no'    => $this->nextBillNo(),
                'vendor_id'  => $vendorId,
                'location_id' => $warehouse->id,
                'date_from'  => $data['date_from'],
                'date_to'    => $data['date_to'],
                'bill_date'  => $data['bill_date'],
                'remarks'    => $data['remarks'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $totQty = $cmtTotal = $fabricTotal = 0.0;
            $ledger = [];
            $warnings = [];

            foreach ($rows->groupBy(fn ($r) => (int) ($r->cat_id ?? 0)) as $catKey => $catRows) {
                $in     = $input[$catKey];
                $rate   = round((float) $in['rate'], 2);
                $meters = round((float) ($in['meters'] ?? 0), 3);
                [$fabId, $fabVid] = $this->parseFabric($in['fabric_choice'] ?? null);
                if (!$fabId) $meters = 0.0;
                $fabRate = $fabId ? Inventory::avgCost($fabId, $fabVid, $data['bill_date']) : 0.0;

                $qty       = (float) $catRows->sum('received_qty');
                $fabricQty = round($qty * $meters, 3);
                $amount    = round($qty * $rate, 2);
                $fabAmount = round($fabricQty * $fabRate, 2);
                $skus      = $catRows->map(fn ($r) => $r->product_id . '-' . ($r->variation_id ?? 0))->unique()->count();

                CmtBillLine::create([
                    'cmt_bill_id' => $bill->id, 'category_id' => $catKey ?: null, 'sku_count' => $skus,
                    'qty' => $qty, 'rate' => $rate, 'amount' => $amount,
                    'fabric_id' => $fabId, 'fabric_variation_id' => $fabVid, 'meters_per_pc' => $meters,
                    'fabric_qty' => $fabricQty, 'fabric_rate' => $fabRate, 'fabric_amount' => $fabAmount,
                ]);

                $unitCost = $rate + $meters * $fabRate;
                foreach ($catRows as $r) {
                    ProductionReceivingDetail::whereKey($r->id)->update([
                        'cmt_bill_id' => $bill->id, 'manufacturing_cost' => $rate,
                        'fabric_id' => $fabId, 'fabric_variation_id' => $fabVid,
                        'fabric_qty' => round((float) $r->received_qty * $meters, 3), 'fabric_rate' => $fabRate,
                    ]);
                    // the FG stock row of this GRN now carries its real cost
                    StockLedger::where('source_type', \App\Models\ProductionReceiving::class)
                        ->where('source_id', $r->rec_id)
                        ->where('product_id', $r->product_id)
                        ->when($r->variation_id, fn ($q) => $q->where('variation_id', $r->variation_id), fn ($q) => $q->whereNull('variation_id'))
                        ->where('qty', '>', 0)
                        ->update(['unit_cost' => round($unitCost, 4)]);
                }

                if ($fabId && $fabricQty > 0) {
                    $ledger[] = [
                        'product_id' => $fabId, 'variation_id' => $fabVid, 'location_id' => $vendorLoc->id,
                        'qty' => -1 * $fabricQty, 'unit_cost' => $fabRate, 'remarks' => 'Fabric consumed ' . $bill->bill_no,
                    ];
                }

                $totQty += $qty; $cmtTotal += $amount; $fabricTotal += $fabAmount;
            }

            $bill->update(['total_qty' => $totQty, 'cmt_amount' => $cmtTotal, 'fabric_amount' => $fabricTotal]);

            $whAcc = $warehouse->inventoryAccountId();
            $entries = [['dr_id' => $whAcc, 'cr_id' => $vendorId, 'amount' => round($cmtTotal, 2), 'remarks' => 'CMT charges — ' . $bill->bill_no]];
            if ($fabricTotal > 0) {
                $entries[] = ['dr_id' => $whAcc, 'cr_id' => $vendorLoc->inventoryAccountId(), 'amount' => round($fabricTotal, 2),
                              'remarks' => 'Fabric consumed at ' . $vendorLoc->name . ' — ' . $bill->bill_no];
            }
            $this->syncVoucherEntries($bill, 'cmt_bill', $data['bill_date'], $entries);
            Inventory::sync($bill, $data['bill_date'], $ledger);

            foreach (collect($ledger)->unique(fn ($l) => $l['product_id'] . '-' . $l['variation_id']) as $l) {
                $bal = Inventory::balance($vendorLoc->id, $l['product_id'], $l['variation_id']);
                if ($bal < -0.0005) {
                    $warnings[] = Inventory::itemLabel($l['product_id'], $l['variation_id']) . ' at ' . $vendorLoc->name . ' is now ' . number_format($bal, 2);
                }
            }

            DB::commit();
            Log::info('[CmtBill] Created', ['id' => $bill->id, 'cmt' => $cmtTotal, 'fabric' => $fabricTotal]);

            $redirect = redirect()->route('cmt_bills.show', $bill->id)->with('success', 'CMT Bill ' . $bill->bill_no . ' posted.');
            return $warnings ? $redirect->with('error', 'Fabric over-use: ' . implode('; ', $warnings) . '.') : $redirect;

        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[CmtBill] Store failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to save CMT Bill: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $bill = CmtBill::with(['vendor', 'lines.category', 'lines.fabric'])->findOrFail($id);
        return view('cmt-bill.show', compact('bill'));
    }

    public function destroy($id)
    {
        $bill = CmtBill::findOrFail($id);
        \App\Support\EntryDate::assertEditable($bill, 'bill_date');

        DB::beginTransaction();
        try {
            $rows = ProductionReceivingDetail::where('cmt_bill_id', $bill->id)->get();
            foreach ($rows as $r) {
                StockLedger::where('source_type', \App\Models\ProductionReceiving::class)
                    ->where('source_id', $r->production_receiving_id)
                    ->where('product_id', $r->product_id)
                    ->when($r->variation_id, fn ($q) => $q->where('variation_id', $r->variation_id), fn ($q) => $q->whereNull('variation_id'))
                    ->where('qty', '>', 0)
                    ->update(['unit_cost' => 0]);
            }
            ProductionReceivingDetail::where('cmt_bill_id', $bill->id)->update([
                'cmt_bill_id' => null, 'manufacturing_cost' => 0,
                'fabric_id' => null, 'fabric_variation_id' => null, 'fabric_qty' => 0, 'fabric_rate' => 0,
            ]);
            $this->deleteVoucherEntries($bill);
            Inventory::clear($bill);
            $bill->lines()->delete();
            $bill->delete();
            DB::commit();
            return redirect()->route('cmt_bills.index')->with('success', 'CMT Bill deleted. Its receivings are unbilled again.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    public function print($id)
    {
        $bill = CmtBill::with(['vendor', 'lines.category', 'lines.fabric'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetTitle('CMT Bill ' . $bill->bill_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 10);

        $html = '<h2>CMT Bill ' . e($bill->bill_no) . '</h2>'
            . '<p>Vendor: <b>' . e($bill->vendor->name ?? '-') . '</b><br>Period: ' . Carbon::parse($bill->date_from)->format('d/m/Y')
            . ' to ' . Carbon::parse($bill->date_to)->format('d/m/Y') . '<br>Bill date: ' . Carbon::parse($bill->bill_date)->format('d/m/Y') . '</p>'
            . '<table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;"><tr style="background-color:#f5f5f5;font-weight:bold;">'
            . '<th width="38%">Category</th><th width="14%">SKUs</th><th width="14%">Qty</th><th width="14%">Rate</th><th width="20%">Amount</th></tr>';
        foreach ($bill->lines as $l) {
            $html .= '<tr><td align="left">' . e($l->category->name ?? 'Uncategorised') . '</td><td>' . $l->sku_count . '</td><td>' . (float) $l->qty
                . '</td><td>' . number_format($l->rate, 2) . '</td><td align="right">' . number_format($l->amount, 2) . '</td></tr>';
        }
        $html .= '<tr style="background-color:#f5f5f5;font-weight:bold;"><td colspan="2" align="right">Total</td><td>' . (float) $bill->total_qty
            . '</td><td></td><td align="right">' . number_format($bill->cmt_amount, 2) . '</td></tr></table>';
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('CMT_' . $bill->bill_no . '.pdf', 'I');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** Unbilled received pieces of a vendor in a date range, grouped by product category. */
    private function candidateGroups(int $vendorId, string $from, string $to)
    {
        $groups = DB::table('production_receiving_details as d')
            ->join('production_receivings as r', 'r.id', '=', 'd.production_receiving_id')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->whereNull('r.deleted_at')
            ->where('r.vendor_id', $vendorId)
            ->whereBetween('r.rec_date', [$from, $to])
            ->whereNull('d.cmt_bill_id')->where('d.legacy_billed', 0)
            ->groupBy('p.category_id')
            ->selectRaw("p.category_id, COUNT(DISTINCT CONCAT(d.product_id,'-',COALESCE(d.variation_id,0))) AS sku_count, SUM(d.received_qty) AS qty, COUNT(DISTINCT r.id) AS grns")
            ->get();

        $names = ProductCategory::whereIn('id', $groups->pluck('category_id')->filter())->pluck('name', 'id');
        return $groups->each(fn ($g) => $g->name = $g->category_id ? ($names[$g->category_id] ?? '#' . $g->category_id) : 'Uncategorised')
            ->sortBy('name')->values();
    }

    /** Fabrics (variations) the vendor currently holds. */
    private function vendorFabrics(int $vendorId)
    {
        $loc = Location::forAccount($vendorId);
        if (!$loc) return collect();

        return StockLedger::where('stock_ledger.location_id', $loc->id)
            ->join('products as p', 'p.id', '=', 'stock_ledger.product_id')
            ->leftJoin('product_variations as v', 'v.id', '=', 'stock_ledger.variation_id')
            ->where('p.item_type', 'raw')
            ->groupBy('stock_ledger.product_id', 'stock_ledger.variation_id', 'p.name', 'v.sku')
            ->havingRaw('SUM(stock_ledger.qty) > 0')
            ->selectRaw('stock_ledger.product_id, stock_ledger.variation_id, p.name, v.sku, SUM(stock_ledger.qty) AS qty')
            ->orderBy('p.name')->get()
            ->map(fn ($f) => (object) [
                'value' => $f->product_id . ':' . ($f->variation_id ?? ''),
                'label' => $f->name . ($f->sku ? ' (' . $f->sku . ')' : '') . ' — ' . round((float) $f->qty, 2) . ' at CMT',
            ]);
    }

    /** Last rate used per category for this vendor. */
    private function lastRates(int $vendorId): array
    {
        $out = [];
        $rows = CmtBillLine::join('cmt_bills as b', 'b.id', '=', 'cmt_bill_lines.cmt_bill_id')
            ->whereNull('b.deleted_at')->where('b.vendor_id', $vendorId)
            ->orderByDesc('cmt_bill_lines.id')->get(['cmt_bill_lines.category_id', 'cmt_bill_lines.rate']);
        foreach ($rows as $r) {
            $out[$r->category_id ?? 0] ??= (float) $r->rate;
        }
        return $out;
    }

    /** "productId:variationId" → [productId|null, variationId|null] */
    private function parseFabric(?string $choice): array
    {
        if (!$choice || !str_contains($choice, ':')) return [null, null];
        [$p, $v] = explode(':', $choice, 2);
        return [(int) $p ?: null, $v !== '' ? (int) $v : null];
    }

    private function nextBillNo(): string
    {
        $last = CmtBill::withTrashed()->where('bill_no', 'like', 'CMT-%')
            ->lockForUpdate()->pluck('bill_no')
            ->map(fn ($n) => (int) substr($n, 4))->max() ?? 0;

        return 'CMT-' . str_pad($last + 1, 5, '0', STR_PAD_LEFT);
    }
}
