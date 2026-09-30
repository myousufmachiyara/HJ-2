<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccounts;
use App\Models\PdcCheque;
use App\Models\PdcChequeBill;
use App\Models\PdcChequeLog;
use App\Models\ProductionReceiving;
use App\Models\PurchaseInvoice;
use App\Traits\PostsAccountingEntries;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * PDC — post-dated cheques issued to vendors against their bills.
 *
 * Accounting (voucher type "pdc", re-synced on every status change):
 *   On issue:                DR Vendor               CR PDC Payable (207010)   [issue date]
 *   On clearing:             DR PDC Payable          CR Bank                   [cleared date]
 *   Bounced / cancelled:     DR PDC Payable          CR Vendor                 [status date]  (issue reversed, vendor owed again)
 */
class PdcChequeController extends Controller
{
    use PostsAccountingEntries;

    public function index(Request $request)
    {
        $query = PdcCheque::with(['vendor', 'bankAccount', 'bills'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->vendor_id))
            ->when($request->filled('bank_account_id'), fn ($q) => $q->where('bank_account_id', $request->bank_account_id))
            ->when($request->filled('from'), fn ($q) => $q->where('cheque_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->where('cheque_date', '<=', $request->to));

        $cheques = $query->orderBy('cheque_date')->get();

        $pending = PdcCheque::whereIn('status', ['issued', 'presented']);
        $summary = [
            'pending_amount' => (clone $pending)->sum('amount'),
            'pending_count'  => (clone $pending)->count(),
            'due_7_amount'   => (clone $pending)->whereBetween('cheque_date', [now()->toDateString(), now()->addDays(7)->toDateString()])->sum('amount'),
            'overdue_amount' => (clone $pending)->where('cheque_date', '<', now()->toDateString())->sum('amount'),
            'bounced_count'  => PdcCheque::where('status', 'bounced')->count(),
        ];

        return view('pdc.index', [
            'cheques'  => $cheques,
            'summary'  => $summary,
            'vendors'  => ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get(['id', 'name']),
            'banks'    => ChartOfAccounts::where('account_type', 'bank')->orderBy('name')->get(['id', 'name']),
            'statuses' => PdcCheque::STATUSES,
        ]);
    }

    public function create(Request $request)
    {
        $replacing = $request->filled('replace') ? PdcCheque::with('bills')->find($request->replace) : null;
        return view('pdc.create', $this->formData() + compact('replacing'));
    }

    public function store(Request $request)
    {
        $data = $this->validateRequest($request);

        DB::beginTransaction();
        try {
            $cheque = PdcCheque::create([
                'pdc_no'          => PdcCheque::nextNo(),
                'vendor_id'       => $data['vendor_id'],
                'bank_account_id' => $data['bank_account_id'],
                'cheque_no'       => $data['cheque_no'],
                'issue_date'      => $data['issue_date'],
                'cheque_date'     => $data['cheque_date'],
                'amount'          => $data['amount'],
                'status'          => 'issued',
                'status_date'     => $data['issue_date'],
                'remarks'         => $data['remarks'] ?? null,
                'created_by'      => auth()->id(),
            ]);
            $this->saveBills($cheque, $data['bills'] ?? []);
            $this->log($cheque, null, 'issued', $data['issue_date'], 'Cheque issued');

            // Replacement for a bounced / cancelled cheque
            if ($request->filled('replaces_id')) {
                $old = PdcCheque::findOrFail($request->replaces_id);
                if (in_array($old->status, ['bounced', 'cancelled'], true)) {
                    $from = $old->status;
                    $old->update(['status' => 'replaced', 'replaced_by_id' => $cheque->id, 'status_date' => $data['issue_date']]);
                    $this->log($old, $from, 'replaced', $data['issue_date'], 'Replaced by ' . $cheque->pdc_no . ' / chq ' . $cheque->cheque_no);
                    $this->post($old);
                }
            }

            $this->post($cheque);
            DB::commit();

            return redirect()->route('pdc_cheques.index')->with('success', 'Cheque ' . $cheque->cheque_no . ' (' . $cheque->pdc_no . ') issued.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[PDC] Store failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to save cheque: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $cheque = PdcCheque::with(['vendor', 'bankAccount', 'bills', 'logs.user', 'replacedBy', 'replaces'])->findOrFail($id);
        return view('pdc.show', ['cheque' => $cheque, 'flow' => PdcCheque::FLOW[$cheque->status] ?? [], 'statuses' => PdcCheque::STATUSES]);
    }

    public function edit($id)
    {
        $cheque = PdcCheque::with('bills')->findOrFail($id);
        if ($cheque->status !== 'issued') {
            return redirect()->route('pdc_cheques.show', $id)->with('error', 'Only cheques in "Issued" status can be edited. Use the status actions instead.');
        }
        return view('pdc.edit', $this->formData() + compact('cheque'));
    }

    public function update(Request $request, $id)
    {
        $cheque = PdcCheque::findOrFail($id);
        if ($cheque->status !== 'issued') {
            return back()->with('error', 'Only cheques in "Issued" status can be edited.');
        }
        $data = $this->validateRequest($request, $cheque);

        DB::beginTransaction();
        try {
            $cheque->update([
                'vendor_id'       => $data['vendor_id'],
                'bank_account_id' => $data['bank_account_id'],
                'cheque_no'       => $data['cheque_no'],
                'issue_date'      => $data['issue_date'],
                'cheque_date'     => $data['cheque_date'],
                'amount'          => $data['amount'],
                'status_date'     => $data['issue_date'],
                'remarks'         => $data['remarks'] ?? null,
            ]);
            $cheque->bills()->delete();
            $this->saveBills($cheque, $data['bills'] ?? []);
            $this->post($cheque);
            DB::commit();

            return redirect()->route('pdc_cheques.show', $cheque->id)->with('success', 'Cheque updated.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to update cheque: ' . $e->getMessage());
        }
    }

    /** POST pdc_cheques/{id}/status  — move the cheque along its life cycle. */
    public function changeStatus(Request $request, $id)
    {
        $cheque = PdcCheque::findOrFail($id);
        $request->validate([
            'status'  => ['required', Rule::in(PdcCheque::FLOW[$cheque->status] ?? [])],
            'date'    => 'required|date',
            'remarks' => 'nullable|string|max:250',
        ], ['status.in' => 'That status change is not allowed from "' . $cheque->statusLabel() . '".']);

        if ($request->status === 'replaced') {
            return redirect()->route('pdc_cheques.create', ['replace' => $cheque->id]);
        }

        DB::beginTransaction();
        try {
            $from = $cheque->status;
            $cheque->update(['status' => $request->status, 'status_date' => $request->date]);
            $this->log($cheque, $from, $request->status, $request->date, $request->remarks);
            $this->post($cheque);
            DB::commit();

            return back()->with('success', 'Cheque ' . $cheque->cheque_no . ' marked as ' . $cheque->statusLabel() . '.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with('error', 'Status change failed: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $cheque = PdcCheque::findOrFail($id);
        if ($cheque->status !== 'issued') {
            return back()->with('error', 'Only cheques still in "Issued" status can be deleted — cancel it instead.');
        }
        DB::transaction(function () use ($cheque) {
            $this->deleteVoucherEntries($cheque);
            $cheque->delete();
        });
        return redirect()->route('pdc_cheques.index')->with('success', 'Cheque deleted.');
    }

    /** AJAX: a vendor's bills with amount already covered by other live cheques. */
    public function vendorBills(Request $request, $vendorId)
    {
        return response()->json($this->billsFor((int) $vendorId, $request->integer('exclude') ?: null));
    }

    public function print($id)
    {
        $cheque = PdcCheque::with(['vendor', 'bankAccount', 'bills'])->findOrFail($id);

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetTitle($cheque->pdc_no);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();

        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) $pdf->Image($logoPath, 5, 11, 50);

        $pdf->SetXY(120, 12);
        $pdf->writeHTML('<table border="1" cellpadding="4" style="font-size:10px;">
            <tr><td><b>PDC #</b></td><td>' . e($cheque->pdc_no) . '</td></tr>
            <tr><td><b>Issue Date</b></td><td>' . $cheque->issue_date->format('d/m/Y') . '</td></tr>
            <tr><td><b>Status</b></td><td>' . e($cheque->statusLabel()) . '</td></tr></table>', false, false, false, false, '');

        $pdf->SetXY(10, 50);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(70, 8, 'Cheque Payment Voucher', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(4);

        $html = '<table border="0.3" cellpadding="5" style="font-size:10px;">
            <tr><td width="30%"><b>Paid To</b></td><td width="70%">' . e($cheque->vendor->name ?? '-') . '</td></tr>
            <tr><td><b>Bank</b></td><td>' . e($cheque->bankAccount->name ?? '-') . '</td></tr>
            <tr><td><b>Cheque No</b></td><td>' . e($cheque->cheque_no) . '</td></tr>
            <tr><td><b>Cheque Date</b></td><td>' . $cheque->cheque_date->format('d/m/Y') . '</td></tr>
            <tr><td><b>Amount</b></td><td><b>PKR ' . number_format($cheque->amount, 2) . '</b></td></tr>
            <tr><td><b>Remarks</b></td><td>' . e($cheque->remarks ?? '') . '</td></tr></table><br>';

        if ($cheque->bills->count()) {
            $html .= '<b>Against Bills</b><table border="0.3" cellpadding="4" style="font-size:10px;">
                <tr style="background-color:#f5f5f5;"><th width="70%">Bill</th><th width="30%" align="right">Amount</th></tr>';
            foreach ($cheque->bills as $b) {
                $html .= '<tr><td>' . e($b->billLabel()) . '</td><td align="right">' . number_format($b->amount, 2) . '</td></tr>';
            }
            $html .= '</table>';
        }
        $pdf->writeHTML($html, true, false, true, false, '');

        $pdf->Ln(20);
        $y = $pdf->GetY();
        $pdf->Line(28, $y, 68, $y);
        $pdf->Line(130, $y, 170, $y);
        $pdf->SetXY(28, $y + 2);  $pdf->Cell(40, 6, 'Received By (Vendor)', 0, 0, 'C');
        $pdf->SetXY(130, $y + 2); $pdf->Cell(40, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output($cheque->pdc_no . '.pdf', 'I');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function formData(): array
    {
        return [
            'vendors' => ChartOfAccounts::where('account_type', 'vendor')->orderBy('name')->get(['id', 'name']),
            'banks'   => ChartOfAccounts::where('account_type', 'bank')->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function validateRequest(Request $request, ?PdcCheque $cheque = null): array
    {
        $data = $request->validate([
            'vendor_id'       => ['required', Rule::exists('chart_of_accounts', 'id')->where('account_type', 'vendor')],
            'bank_account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('account_type', 'bank')],
            'cheque_no'       => ['required', 'string', 'max:50',
                                  Rule::unique('pdc_cheques', 'cheque_no')->where('bank_account_id', $request->bank_account_id)->ignore($cheque?->id)],
            'issue_date'      => 'required|date',
            'cheque_date'     => 'required|date',
            'amount'          => 'required|numeric|min:1',
            'remarks'         => 'nullable|string|max:1000',
            'bills'           => 'nullable|array',
            'bills.*.type'    => 'required_with:bills|in:purchase,receiving',
            'bills.*.id'      => 'required_with:bills|integer',
            'bills.*.amount'  => 'nullable|numeric|min:0',
        ], ['cheque_no.unique' => 'This cheque number is already recorded for this bank account.']);

        $bills = collect($data['bills'] ?? [])->filter(fn ($b) => (float) ($b['amount'] ?? 0) > 0)->values()->all();
        $allocated = array_sum(array_map(fn ($b) => (float) $b['amount'], $bills));

        if ($allocated - (float) $data['amount'] > 0.009) {
            throw ValidationException::withMessages(['amount' => 'Amount allocated to bills (' . number_format($allocated, 2) . ') is more than the cheque amount.']);
        }

        // allocation cannot exceed what is still open on each bill
        $open = collect($this->billsFor((int) $data['vendor_id'], $cheque?->id))->keyBy(fn ($b) => $b['type'] . '-' . $b['id']);
        foreach ($bills as $b) {
            $row = $open->get($b['type'] . '-' . $b['id']);
            if (!$row) {
                throw ValidationException::withMessages(['bills' => 'A selected bill does not belong to this vendor.']);
            }
            if ((float) $b['amount'] - $row['balance'] > 0.009) {
                throw ValidationException::withMessages(['bills' => $row['label'] . ': only ' . number_format($row['balance'], 2) . ' is still open on this bill.']);
            }
        }

        $data['bills'] = $bills;
        return $data;
    }

    /** Vendor bills (purchase invoices + FG receivings) with amounts already covered by other live cheques. */
    private function billsFor(int $vendorId, ?int $excludeChequeId = null): array
    {
        $covered = PdcChequeBill::whereHas('cheque', fn ($q) => $q->whereIn('status', PdcCheque::LIVE)
                ->when($excludeChequeId, fn ($w) => $w->where('id', '!=', $excludeChequeId)))
            ->selectRaw("CONCAT(bill_type, '-', bill_id) AS k, SUM(amount) AS amt")
            ->groupBy('bill_type', 'bill_id')->pluck('amt', 'k');

        $rows = [];

        PurchaseInvoice::with('items')->where('vendor_id', $vendorId)->orderBy('invoice_date')->get()
            ->each(function ($inv) use (&$rows, $covered) {
                $total = $inv->items->sum(fn ($i) => $i->quantity * $i->price)
                    + (float) $inv->convance_charges + (float) $inv->labour_charges - (float) $inv->bill_discount;
                $paid = (float) ($covered['purchase-' . $inv->id] ?? 0);
                $rows[] = [
                    'type' => 'purchase', 'id' => $inv->id,
                    'label' => 'Purchase ' . $inv->invoice_no . ($inv->bill_no ? ' / Bill ' . $inv->bill_no : ''),
                    'date' => Carbon::parse($inv->invoice_date)->format('d-M-Y'),
                    'total' => round($total, 2), 'covered' => round($paid, 2), 'balance' => round($total - $paid, 2),
                ];
            });

        ProductionReceiving::with('details')->where('vendor_id', $vendorId)->orderBy('rec_date')->get()
            ->each(function ($rec) use (&$rows, $covered) {
                $total = $rec->details->sum(fn ($d) => $d->received_qty * $d->manufacturing_cost)
                    + (float) $rec->convance_charges - (float) $rec->bill_discount;
                $paid = (float) ($covered['receiving-' . $rec->id] ?? 0);
                $rows[] = [
                    'type' => 'receiving', 'id' => $rec->id,
                    'label' => 'CMT Bill ' . $rec->grn_no,
                    'date' => Carbon::parse($rec->rec_date)->format('d-M-Y'),
                    'total' => round($total, 2), 'covered' => round($paid, 2), 'balance' => round($total - $paid, 2),
                ];
            });

        return $rows;
    }

    private function saveBills(PdcCheque $cheque, array $bills): void
    {
        foreach ($bills as $b) {
            PdcChequeBill::create([
                'pdc_cheque_id' => $cheque->id,
                'bill_type'     => $b['type'],
                'bill_id'       => (int) $b['id'],
                'amount'        => (float) $b['amount'],
            ]);
        }
    }

    private function log(PdcCheque $cheque, ?string $from, string $to, $date, ?string $remarks): void
    {
        PdcChequeLog::create([
            'pdc_cheque_id' => $cheque->id,
            'from_status'   => $from,
            'to_status'     => $to,
            'date'          => $date,
            'remarks'       => $remarks,
            'user_id'       => auth()->id(),
        ]);
    }

    /** Rebuild this cheque's vouchers from its current status. */
    private function post(PdcCheque $cheque): void
    {
        $label   = 'Chq ' . $cheque->cheque_no . ' (' . $cheque->pdc_no . ')';
        $issue   = $cheque->issue_date->toDateString();
        $status  = ($cheque->status_date ?? $cheque->issue_date)->toDateString();
        $entries = [
            ['dr_id' => $cheque->vendor_id, 'cr' => '207010', 'amount' => $cheque->amount, 'remarks' => 'PDC issued — ' . $label, 'date' => $issue],
        ];

        if ($cheque->status === 'cleared') {
            $entries[] = ['dr' => '207010', 'cr_id' => $cheque->bank_account_id, 'amount' => $cheque->amount, 'remarks' => 'PDC cleared — ' . $label, 'date' => $status];
        }
        if (in_array($cheque->status, ['bounced', 'cancelled', 'replaced'], true)) {
            $when = $cheque->logs()->whereIn('to_status', ['bounced', 'cancelled'])->value('date');
            $entries[] = ['dr' => '207010', 'cr_id' => $cheque->vendor_id, 'amount' => $cheque->amount,
                          'remarks' => 'PDC ' . ($cheque->status === 'cancelled' ? 'cancelled' : 'bounced') . ' — ' . $label,
                          'date' => $when ? Carbon::parse($when)->toDateString() : $status];
        }

        // syncVoucherEntries uses one date per call; group entries by date.
        \App\Models\Voucher::where('source_type', get_class($cheque))->where('source_id', $cheque->id)->delete();
        foreach (collect($entries)->groupBy('date') as $date => $group) {
            $this->appendVoucherEntries($cheque, 'pdc', $date, $group->all());
        }
    }

    /** Like syncVoucherEntries() but without wiping existing rows first. */
    private function appendVoucherEntries(PdcCheque $cheque, string $type, string $date, array $entries): void
    {
        foreach ($entries as $e) {
            \App\Models\Voucher::create([
                'voucher_type' => $type,
                'source_type'  => get_class($cheque),
                'source_id'    => $cheque->id,
                'date'         => $date,
                'ac_dr_sid'    => $e['dr_id'] ?? $this->accountId($e['dr']),
                'ac_cr_sid'    => $e['cr_id'] ?? $this->accountId($e['cr']),
                'amount'       => $e['amount'],
                'remarks'      => $e['remarks'],
                'attachments'  => [],
            ]);
        }
    }
}
