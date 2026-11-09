<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\LaamInvoice;
use App\Models\LaamInvoiceItem;
use App\Models\Location;
use App\Services\Inventory;
use App\Services\LaamSheetReader;
use App\Support\EntryDate;
use App\Support\HjSetup;
use App\Traits\PostsAccountingEntries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * LAAM weekly sale settlement.
 *
 *  upload LAAM "Ledgers" export  →  check (products matched, nothing imported twice)  →  save
 *
 *  Saving does two things:
 *    1. stock   : every SOLD piece leaves the LAAM stock location (stock_ledger, −1 per Item Id)
 *    2. ledger  : LAAM's receivable account is debited with what LAAM owes us (Seller Payable)
 *
 *    DR  LAAM (receivable)            Realized Sales            CR Sales Revenue (401001)
 *    DR  Marketplace Platform Fee     Net Platform Fee          CR LAAM
 *    DR  PRA Tax                      PRA Tax                   CR LAAM
 *    DR  Sales Tax Withheld (asset)   Sales Tax                 CR LAAM
 *    DR  Income Tax Withheld (asset)  Income Tax                CR LAAM
 *    DR/CR Sales Discount (401003)    Seller discounts / coupon / rounding (file's Seller Payable
 *                                     minus Realized − fee − taxes)        vs LAAM
 *    DR  COGS (501001)                average cost                CR Stock @ LAAM
 *
 *  Net effect on the LAAM account = Seller Payable, exactly as printed in LAAM's file.
 *  When LAAM pays, record a normal receipt voucher against the LAAM account.
 */
class LaamInvoiceController extends Controller
{
    use PostsAccountingEntries;

    public function __construct(protected LaamSheetReader $reader) {}

    // ── List ──────────────────────────────────────────────────────────

    public function index()
    {
        $invoices = LaamInvoice::with(['location', 'account'])->withCount('items')->latest('date')->latest('id')->get();
        return view('laam.index', compact('invoices'));
    }

    // ── New settlement ────────────────────────────────────────────────

    public function create()
    {
        $locationGroups = Location::grouped();
        $laam = Location::customers()->where('name', 'like', '%laam%')->first();
        return view('laam.create', [
            'locationGroups' => $locationGroups,
            'defaultLocationId' => $laam?->id,
        ]);
    }

    /** Step 1 (AJAX): read the uploaded file, keep it, return rows + totals for review. */
    public function parse(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt']);

        // keep the real extension so the reader picks the right format (txt = csv)
        $ext    = strtolower($request->file('file')->getClientOriginalExtension());
        $ext    = $ext === 'txt' ? 'csv' : $ext;
        $path   = $request->file('file')->storeAs('laam-tmp', \Illuminate\Support\Str::random(40) . '.' . $ext, 'local');
        $result = $this->reader->read($path);

        if (!$result['rows'] && !$result['skipped']) {
            Storage::disk('local')->delete($path);
            return response()->json(['success' => false, 'message' => $result['errors'][0] ?? 'No rows found in the file.'], 422);
        }

        return response()->json($result + [
            'success'   => true,
            'token'     => $path,
            'file_name' => $request->file('file')->getClientOriginalName(),
        ]);
    }

    /** Step 2: re-read the kept file (never trust numbers sent back by the browser) and post it. */
    public function store(Request $request)
    {
        EntryDate::assertNew($request->input('date'), 'date');
        $request->validate([
            'date'        => 'required|date',
            'location_id' => 'required|exists:locations,id',
            'token'       => 'required|string',
            'file_name'   => 'nullable|string|max:255',
            'ref_no'      => 'nullable|string|max:100',
            'remarks'     => 'nullable|string',
        ]);

        $token = (string) $request->token;
        if (!str_starts_with($token, 'laam-tmp/') || str_contains($token, '..') || !Storage::disk('local')->exists($token)) {
            return back()->withInput()->with('error', 'The uploaded file expired. Please choose the file again.');
        }

        $location = Location::findOrFail($request->location_id);
        if (!$location->isCustomer() || !$location->chart_of_account_id) {
            return back()->withInput()->with('error', 'Select the LAAM location (a Customer / Marketplace location linked to the LAAM account).');
        }

        $data = $this->reader->read($token);
        $rows = collect($data['rows']);

        if ($rows->isEmpty())                       return back()->withInput()->with('error', 'No SOLD rows to post in this file.');
        if ($data['errors'])                        return back()->withInput()->with('error', implode(' | ', array_slice($data['errors'], 0, 5)));
        if ($bad = $rows->firstWhere('duplicate', true)) {
            return back()->withInput()->with('error', "Item Id {$bad['item_id']} (row {$bad['row']}) was already imported in an earlier settlement.");
        }
        if ($bad = $rows->first(fn ($r) => !$r['product_id'])) {
            return back()->withInput()->with('error', "Row {$bad['row']}: {$bad['match_error']}");
        }

        // enough stock at LAAM?
        $need = $rows->groupBy(fn ($r) => $r['product_id'] . '-' . ($r['variation_id'] ?? 0))
            ->map(fn ($g) => ['product_id' => $g[0]['product_id'], 'variation_id' => $g[0]['variation_id'], 'qty' => $g->sum('quantity')])->values()->all();
        try {
            Inventory::assertAvailable((int) $location->id, $need);
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->take(5)->implode(' | '));
        }

        DB::beginTransaction();
        try {
            $this->ensureSystemAccounts();

            $invoiceNo = 'LAAM-' . str_pad(LaamInvoice::withTrashed()->count() + 1, 5, '0', STR_PAD_LEFT);
            $t = $data['totals'];

            $invoice = LaamInvoice::create([
                'invoice_no'       => $invoiceNo,
                'date'             => $request->date,
                'period_from'      => $data['period']['from'] ?? null,
                'period_to'        => $data['period']['to'] ?? null,
                'location_id'      => $location->id,
                'account_id'       => $location->chart_of_account_id,
                'ref_no'           => $request->ref_no,
                'file_name'        => $request->file_name,
                'remarks'          => $request->remarks,
                'total_retail'     => $t['retail'],
                'total_realized'   => $t['realized'],
                'total_fee'        => $t['fee'],
                'total_pra_tax'    => $t['pra_tax'],
                'total_sales_tax'  => $t['sales_tax'],
                'total_income_tax' => $t['income_tax'],
                'total_adjustment' => $t['adjustment'],
                'total_payable'    => $t['payable'],
                'created_by'       => auth()->id(),
            ]);

            foreach ($rows as $r) {
                $invoice->items()->create([
                    'product_id'           => $r['product_id'],
                    'variation_id'         => $r['variation_id'],
                    'item_name'            => $r['name'] ?: $r['matched_name'],
                    'sku'                  => $r['matched_sku'] ?? $r['sku'],
                    'platform'             => $r['platform'],
                    'brand'                => $r['brand'],
                    'order_number'         => $r['order_number'],
                    'shopify_order_number' => $r['shopify_order'],
                    'order_date'           => $r['order_date'],
                    'destination'          => $r['destination'],
                    'payment_method'       => $r['payment_method'],
                    'item_status'          => $r['status'],
                    'quantity'             => $r['quantity'],
                    'actual_price'         => $r['actual_price'],
                    'retail_price'         => $r['retail_price'],
                    'realized_sales'       => $r['realized'],
                    'coupon_code'          => $r['coupon_code'] ?: null,
                    'platform_fee_pct'     => $r['fee_pct'],
                    'seller_discount'      => $r['seller_discount'],
                    'net_platform_fee'     => $r['net_fee'],
                    'pra_tax'              => $r['pra_tax'],
                    'sales_tax'            => $r['sales_tax'],
                    'income_tax'           => $r['income_tax'],
                    'seller_payable'       => $r['seller_payable'],
                    'item_id'              => $r['item_id'],
                    'realization_date'     => $r['realization'],
                    'notes'                => $r['notes'] ?: null,
                    'unit_cost'            => Inventory::avgCost((int) $r['product_id'], $r['variation_id'] ? (int) $r['variation_id'] : null, $invoice->date->toDateString()),
                ]);
            }

            $invoice->load('items');
            $this->post($invoice);

            // keep the original file with the settlement (audit trail)
            $ext  = pathinfo($token, PATHINFO_EXTENSION) ?: 'xlsx';
            $keep = 'laam-imports/' . $invoiceNo . '.' . $ext;
            Storage::disk('local')->makeDirectory('laam-imports');
            Storage::disk('local')->move($token, $keep);

            DB::commit();
            Log::info('[LAAM] Settlement saved', ['id' => $invoice->id, 'rows' => $rows->count(), 'payable' => $t['payable']]);

            return redirect()->route('laam_invoices.show', $invoice->id)
                ->with('success', "{$invoiceNo} saved — {$rows->count()} piece(s) left LAAM stock and PKR " . number_format($t['payable'], 2) . ' was added to LAAM receivable.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[LAAM] Save failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to save: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $invoice = LaamInvoice::with(['location', 'account', 'items.product', 'items.variation'])->findOrFail($id);
        return view('laam.show', compact('invoice'));
    }

    /** Remove a settlement: stock returns to LAAM, voucher entries are removed, Item Ids can be uploaded again. */
    public function destroy($id)
    {
        $invoice = LaamInvoice::findOrFail($id);
        EntryDate::assertEditable($invoice, 'date');

        DB::beginTransaction();
        try {
            $this->deleteVoucherEntries($invoice);
            Inventory::clear($invoice);
            $invoice->items()->delete();
            $invoice->delete();
            DB::commit();
            return redirect()->route('laam_invoices.index')->with('success', 'LAAM settlement deleted.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Delete failed: ' . $e->getMessage());
        }
    }

    // ── Accounting + stock ────────────────────────────────────────────

    private function post(LaamInvoice $inv): void
    {
        $ar  = (int) $inv->account_id;
        $no  = $inv->invoice_no;
        $loc = $inv->location;
        $e   = [];

        $e[] = ['dr_id' => $ar, 'cr' => '401001', 'amount' => $inv->total_realized,   'remarks' => "LAAM sales — {$no}"];
        $e[] = ['dr' => '502020', 'cr_id' => $ar, 'amount' => $inv->total_fee,        'remarks' => "LAAM platform fee — {$no}"];
        $e[] = ['dr' => '502021', 'cr_id' => $ar, 'amount' => $inv->total_pra_tax,    'remarks' => "LAAM PRA tax — {$no}"];
        $e[] = ['dr' => '105010', 'cr_id' => $ar, 'amount' => $inv->total_sales_tax,  'remarks' => "LAAM sales tax withheld — {$no}"];
        $e[] = ['dr' => '105011', 'cr_id' => $ar, 'amount' => $inv->total_income_tax, 'remarks' => "LAAM income tax withheld — {$no}"];

        $adj = (float) $inv->total_adjustment;           // Seller Payable − (Realized − fee − taxes)
        if ($adj < 0) {
            $e[] = ['dr' => '401003', 'cr_id' => $ar, 'amount' => abs($adj), 'remarks' => "LAAM discounts / coupons — {$no}"];
        } elseif ($adj > 0) {
            $e[] = ['dr_id' => $ar, 'cr' => '401003', 'amount' => $adj, 'remarks' => "LAAM adjustment — {$no}"];
        }

        $cogs = round($inv->items->sum(fn ($i) => (float) $i->unit_cost * (float) $i->quantity), 2);
        if ($cogs > 0) {
            $e[] = ['dr' => '501001', 'cr_id' => $loc->inventoryAccountId(), 'amount' => $cogs, 'remarks' => "COGS (LAAM) — {$no}"];
        }

        $this->syncVoucherEntries($inv, 'sale', $inv->date->toDateString(), $e);

        Inventory::sync($inv, $inv->date->toDateString(), $inv->items->map(fn ($i) => [
            'product_id'   => $i->product_id,
            'variation_id' => $i->variation_id,
            'location_id'  => $loc->id,
            'qty'          => -1 * (float) $i->quantity,
            'unit_cost'    => (float) $i->unit_cost,
            'remarks'      => 'LAAM sale ' . $no,
        ])->all());
    }

    /** The new system accounts (created by HjSetup) — create them now if setup was not re-run. */
    private function ensureSystemAccounts(): void
    {
        $codes = ['401001', '401003', '501001', '502020', '502021', '105010', '105011'];
        if (ChartOfAccounts::whereIn('account_code', $codes)->count() < count($codes)) {
            HjSetup::run();
        }
        foreach ($codes as $c) {
            $this->accountId($c);   // throws a clear message if one is still missing
        }
    }
}
