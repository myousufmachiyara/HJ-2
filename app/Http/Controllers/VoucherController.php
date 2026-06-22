<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class VoucherController extends Controller
{
    // Manual voucher types — system types are everything else
    private const MANUAL_TYPES = ['journal', 'payment', 'receipt'];

    public function index($type = null)
    {
        $accounts = ChartOfAccounts::orderBy('name')->get();

        $journal = Voucher::with(['debitAccount', 'creditAccount'])->where('voucher_type', 'journal')->latest()->get();
        $payment = Voucher::with(['debitAccount', 'creditAccount'])->where('voucher_type', 'payment')->latest()->get();
        $receipt = Voucher::with(['debitAccount', 'creditAccount'])->where('voucher_type', 'receipt')->latest()->get();
        $system  = Voucher::with(['debitAccount', 'creditAccount'])->whereNotIn('voucher_type', self::MANUAL_TYPES)->latest()->get();

        return view('vouchers.index', compact('accounts', 'journal', 'payment', 'receipt', 'system'));
    }

    public function show($type, $id)
    {
        $voucher = Voucher::with(['debitAccount', 'creditAccount'])->findOrFail($id);
        return response()->json($voucher);
    }

    public function store(Request $request, $type)
    {
        // Block creating system vouchers manually
        if (!in_array($type, self::MANUAL_TYPES)) {
            return back()->with('error', "Cannot manually create a '{$type}' voucher.");
        }

        try {
            $data = $request->validate([
                'date'      => 'required|date',
                'ac_dr_sid' => 'required|numeric',
                'ac_cr_sid' => 'required|numeric|different:ac_dr_sid',
                'amount'    => 'required|numeric|min:1',
                'remarks'   => 'nullable|string',
                'att.*'     => 'nullable|file|max:2048',
            ]);

            $attachments = [];
            if ($request->hasFile('att')) {
                foreach ($request->file('att') as $file) {
                    $attachments[] = $file->store("attachments/{$type}", 'public');
                }
            }

            Voucher::create([
                'voucher_type' => $type,
                'date'         => $data['date'],
                'ac_dr_sid'    => $data['ac_dr_sid'],
                'ac_cr_sid'    => $data['ac_cr_sid'],
                'amount'       => $data['amount'],
                'remarks'      => $data['remarks'] ?? null,
                'attachments'  => $attachments,
            ]);

            return back()->with('success', ucfirst($type) . ' voucher added successfully!');

        } catch (\Throwable $e) {
            Log::error("Error storing {$type} voucher: " . $e->getMessage(), [
                'trace'   => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->with('error', 'Something went wrong while adding the voucher.');
        }
    }

    public function update(Request $request, $type, $id)
    {
        // Block editing system vouchers
        if (!in_array($type, self::MANUAL_TYPES)) {
            return back()->with('error', "Cannot manually edit a '{$type}' voucher.");
        }

        try {
            $data = $request->validate([
                'date'      => 'required|date',
                'ac_dr_sid' => 'required|numeric',
                'ac_cr_sid' => 'required|numeric|different:ac_dr_sid',
                'amount'    => 'required|numeric|min:1',
                'remarks'   => 'nullable|string',
                'att.*'     => 'nullable|file|max:2048',
            ]);

            $voucher     = Voucher::findOrFail($id);
            $attachments = $voucher->attachments ?? [];

            if ($request->hasFile('att')) {
                foreach ($request->file('att') as $file) {
                    $attachments[] = $file->store("attachments/{$type}", 'public');
                }
            }

            $voucher->update([
                'date'        => $data['date'],
                'ac_dr_sid'   => $data['ac_dr_sid'],
                'ac_cr_sid'   => $data['ac_cr_sid'],
                'amount'      => $data['amount'],
                'remarks'     => $data['remarks'] ?? null,
                'attachments' => $attachments,
            ]);

            return back()->with('success', ucfirst($type) . ' voucher updated successfully!');

        } catch (\Throwable $e) {
            Log::error("Error updating {$type} voucher ID {$id}: " . $e->getMessage(), [
                'trace'   => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->with('error', 'Something went wrong while updating the voucher.');
        }
    }

    public function destroy($type, $id)
    {
        // Block deleting system vouchers
        if (!in_array($type, self::MANUAL_TYPES)) {
            return back()->with('error', "Cannot manually delete a '{$type}' voucher.");
        }

        try {
            $voucher = Voucher::findOrFail($id);

            if (!empty($voucher->attachments)) {
                foreach ($voucher->attachments as $file) {
                    if (Storage::disk('public')->exists($file)) {
                        Storage::disk('public')->delete($file);
                    }
                }
            }

            $voucher->delete();

            return back()->with('success', ucfirst($type) . ' voucher deleted successfully.');

        } catch (\Throwable $e) {
            Log::error("Error deleting {$type} voucher ID {$id}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->with('error', 'Something went wrong while deleting the voucher.');
        }
    }

    public function print($type, $id)
    {
        $voucher = Voucher::with(['debitAccount', 'creditAccount'])->findOrFail($id);

        // For system vouchers, group all rows belonging to the same source
        $relatedVouchers = collect([$voucher]);
        if ($voucher->source_type && $voucher->source_id) {
            $relatedVouchers = Voucher::with(['debitAccount', 'creditAccount'])
                ->where('source_type', $voucher->source_type)
                ->where('source_id',   $voucher->source_id)
                ->get();
        }

        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Jild');
        $pdf->SetAuthor('Jild');
        $pdf->SetTitle(ucwords(str_replace('_', ' ', $type)) . ' Voucher #' . $voucher->source_id ?? $voucher->id);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->setCellPadding(1.5);

        // Logo
        $logoPath = public_path('assets/img/hj-logo.jpg');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 10, 10, 30);
        }

        // Info box
        $pdf->SetXY(130, 12);
        $pdf->writeHTML('
        <table cellpadding="2" style="font-size:10px; line-height:14px;">
            <tr><td><b>Voucher #</b></td><td>' . ($voucher->source_id ?? $voucher->id) . '</td></tr>
            <tr><td><b>Type</b></td><td>' . ucwords(str_replace('_', ' ', $type)) . '</td></tr>
            <tr><td><b>Date</b></td><td>' . \Carbon\Carbon::parse($voucher->date)->format('d/m/Y') . '</td></tr>
        </table>', false, false, false, false, '');

        $pdf->Line(60, 52.25, 200, 52.25);

        // Title bar
        $pdf->SetXY(10, 48);
        $pdf->SetFillColor(23, 54, 93);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(50, 8, ucwords(str_replace('_', ' ', $type)) . ' Voucher', 0, 1, 'C', 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);

        // Entries table
        $html = '
        <table border="0.3" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="background-color:#f5f5f5; font-weight:bold;">
                <th width="8%">S.No</th>
                <th width="35%">Debit Account</th>
                <th width="35%">Credit Account</th>
                <th width="22%">Amount</th>
            </tr>';

        $grandTotal = 0;
        foreach ($relatedVouchers as $i => $row) {
            $grandTotal += $row->amount;
            $html .= '
            <tr>
                <td>' . ($i + 1) . '</td>
                <td align="left">' . ($row->debitAccount->name  ?? '-') . '</td>
                <td align="left">' . ($row->creditAccount->name ?? '-') . '</td>
                <td align="right">' . number_format($row->amount, 2) . '</td>
            </tr>';
        }

        $html .= '
            <tr style="background-color:#f5f5f5;">
                <td colspan="3" align="right"><b>Total</b></td>
                <td align="right"><b>' . number_format($grandTotal, 2) . '</b></td>
            </tr>
        </table>';

        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(5);

        if (!empty($voucher->remarks)) {
            $pdf->writeHTML(
                '<b>Remarks:</b><br><span style="font-size:12px;">' . nl2br($voucher->remarks) . '</span>',
                true, false, true, false, ''
            );
        }

        // Signatures
        $pdf->Ln(20);
        $y = $pdf->GetY();
        $pdf->Line(28,  $y, 68,  $y);
        $pdf->Line(130, $y, 170, $y);
        $pdf->SetXY(28,  $y + 2); $pdf->Cell(40, 6, 'Prepared By',   0, 0, 'C');
        $pdf->SetXY(130, $y + 2); $pdf->Cell(40, 6, 'Authorized By', 0, 0, 'C');

        return $pdf->Output(strtolower(str_replace(' ', '_', $type)) . '_voucher_' . $voucher->id . '.pdf', 'I');
    }
}