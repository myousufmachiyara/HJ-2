<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Voucher;
use App\Models\ChartOfAccounts;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountsReportController extends Controller
{
    public function accounts(Request $request)
    {
        $from   = $request->from_date ?? Carbon::now()->startOfMonth()->toDateString();
        $to     = $request->to_date   ?? Carbon::now()->endOfMonth()->toDateString();
        $report = $request->report    ?? 'general_ledger';

        $chartOfAccounts = ChartOfAccounts::orderBy('account_code')->get();

        $accountId = in_array($report, ['general_ledger', 'party_ledger'])
            ? ($request->account_id ? (int) $request->account_id : null)
            : null;

        $reportData = match ($report) {
            'general_ledger'   => $this->generalLedger($accountId, $from, $to),
            'trial_balance'    => $this->trialBalance($from, $to),
            'profit_loss'      => $this->profitLoss($from, $to),
            'balance_sheet'    => $this->balanceSheet($from, $to),
            'party_ledger'     => $this->partyLedger($from, $to, $accountId),
            'receivables'      => $this->receivables($from, $to),
            'payables'         => $this->payables($from, $to),
            'cash_book'        => $this->cashBook($from, $to),
            'bank_book'        => $this->bankBook($from, $to),
            'journal_book'     => $this->journalBook($from, $to),
            'expense_analysis' => $this->expenseAnalysis($from, $to),
            'cash_flow'        => $this->cashFlow($from, $to),
            default            => collect(),
        };

        return view('reports.accounts_reports', compact(
            'reportData', 'from', 'to', 'report', 'chartOfAccounts', 'accountId'
        ));
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function fmt(float|int|string $value): string
    {
        return number_format((float) $value, 2);
    }

    private function unformat(string $value): float
    {
        return (float) str_replace(',', '', $value);
    }

    private function runningBalance(array $rows, float $openingBalance = 0): array
    {
        $balance = $openingBalance;
        foreach ($rows as &$row) {
            $balance += $this->unformat($row['debit']) - $this->unformat($row['credit']);
            $row['balance']    = $this->fmt(abs($balance));
            $row['balance_dr'] = $balance >= 0; // true = DR side, false = CR side
        }
        unset($row);
        return $rows;
    }

    /**
     * Net opening balance for a vendor/customer account, as a signed DR amount.
     * receivables = opening DR (they owe us)  → positive
     * payables    = opening CR (we owe them)  → negative
     * Returns 0 for non-party account types.
     */
    private function partyOpeningBalance(ChartOfAccounts $account): float
    {
        if (!in_array($account->account_type, ['customer', 'vendor'])) {
            return 0;
        }

        $receivables = (float) ($account->receivables ?? 0);
        $payables    = (float) ($account->payables ?? 0);

        return $receivables - $payables;
    }

    private function partyOpeningBalanceById(int $accountId): float
    {
        $account = ChartOfAccounts::find($accountId);
        return $account ? $this->partyOpeningBalance($account) : 0;
    }

    // ── Voucher type label ────────────────────────────────────────────
    private function voucherLabel(Voucher $v): string
    {
        $typeMap = [
            'purchase'             => 'Purchase',
            'purchase_return'      => 'Purchase Return',
            'sale'                 => 'Sale',
            'sale_return'          => 'Sale Return',
            'production'           => 'Production Order',
            'production_receiving' => 'Production Receiving',
            'production_return'    => 'Production Return',
            'production_wastage'   => 'Wastage Return',
            'receipt'              => 'Receipt',
            'payment'              => 'Payment',
            'journal'              => 'Journal',
            'contra'               => 'Contra',
            'stock_transfer'       => 'Stock Transfer',
        ];

        $label   = $typeMap[$v->voucher_type] ?? ucwords(str_replace('_', ' ', $v->voucher_type));
        $refId   = $v->source_id ?? $v->id;
        $remarks = $v->remarks ? ' — ' . Str::limit($v->remarks, 50) : '';

        return $label . ' #' . $refId . $remarks;
    }

    // ── 1. General Ledger ─────────────────────────────────────────────
    private function generalLedger(?int $accountId, string $from, string $to): array
    {
        if (!$accountId) return [];

        $account        = ChartOfAccounts::find($accountId);
        $openingBalance = $account ? $this->partyOpeningBalance($account) : 0;

        $vouchers = Voucher::with(['debitAccount', 'creditAccount'])
            ->whereBetween('date', [$from, $to])
            ->where(fn($q) => $q->where('ac_dr_sid', $accountId)
                                ->orWhere('ac_cr_sid', $accountId))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $rows = $vouchers->map(function ($v) use ($accountId) {
            $isDebit = $v->ac_dr_sid == $accountId;
            $contra  = $isDebit
                ? ($v->creditAccount->name ?? '-')
                : ($v->debitAccount->name  ?? '-');

            return [
                'date'    => $v->date,
                'voucher' => $this->voucherLabel($v),
                'account' => $contra,
                'debit'   => $isDebit  ? $this->fmt($v->amount) : '0.00',
                'credit'  => !$isDebit ? $this->fmt($v->amount) : '0.00',
                'balance' => '0.00',
                'balance_dr' => true,
            ];
        })->toArray();

        // Prepend an opening balance row if non-zero
        if ($openingBalance != 0) {
            array_unshift($rows, [
                'date'       => $from,
                'voucher'    => 'Opening Balance',
                'account'    => '-',
                'debit'      => $openingBalance > 0 ? $this->fmt($openingBalance) : '0.00',
                'credit'     => $openingBalance < 0 ? $this->fmt(abs($openingBalance)) : '0.00',
                'balance'    => '0.00',
                'balance_dr' => true,
            ]);
        }

        return $this->runningBalance($rows);
    }

    // ── 2. Trial Balance ──────────────────────────────────────────────
    private function trialBalance(string $from, string $to): \Illuminate\Support\Collection
    {
        $debits = DB::table('vouchers')
            ->join('chart_of_accounts as coa', 'vouchers.ac_dr_sid', '=', 'coa.id')
            ->whereBetween('vouchers.date', [$from, $to])
            ->whereNull('vouchers.deleted_at')
            ->select(
                'coa.id', 'coa.account_code', 'coa.name', 'coa.account_type',
                DB::raw('SUM(vouchers.amount) as total_debit'),
                DB::raw('0 as total_credit')
            )
            ->groupBy('coa.id', 'coa.account_code', 'coa.name', 'coa.account_type');

        $credits = DB::table('vouchers')
            ->join('chart_of_accounts as coa', 'vouchers.ac_cr_sid', '=', 'coa.id')
            ->whereBetween('vouchers.date', [$from, $to])
            ->whereNull('vouchers.deleted_at')
            ->select(
                'coa.id', 'coa.account_code', 'coa.name', 'coa.account_type',
                DB::raw('0 as total_debit'),
                DB::raw('SUM(vouchers.amount) as total_credit')
            )
            ->groupBy('coa.id', 'coa.account_code', 'coa.name', 'coa.account_type');

        $voucherTotals = $debits->unionAll($credits)
            ->get()
            ->groupBy('id')
            ->map(function ($rows) {
                $first  = $rows->first();
                return [
                    'id'           => $first->id,
                    'account_code' => $first->account_code,
                    'account'      => $first->name,
                    'account_type' => $first->account_type,
                    'debit'        => $rows->sum('total_debit'),
                    'credit'       => $rows->sum('total_credit'),
                ];
            });

        // ── Fold in opening balances for vendor/customer accounts ──────
        $partyAccounts = ChartOfAccounts::whereIn('account_type', ['customer', 'vendor'])->get();

        foreach ($partyAccounts as $account) {
            $opening = $this->partyOpeningBalance($account);
            if ($opening == 0) continue;

            $existing = $voucherTotals->get($account->id);

            $debit  = $existing['debit']  ?? 0;
            $credit = $existing['credit'] ?? 0;

            // Add opening DR to debit side, opening CR to credit side
            if ($opening > 0) {
                $debit += $opening;
            } else {
                $credit += abs($opening);
            }

            $voucherTotals->put($account->id, [
                'id'           => $account->id,
                'account_code' => $account->account_code,
                'account'      => $account->name,
                'account_type' => $account->account_type,
                'debit'        => $debit,
                'credit'       => $credit,
            ]);
        }

        return $voucherTotals
            ->map(function ($row) {
                $net = $row['debit'] - $row['credit'];
                return [
                    'account_code' => $row['account_code'],
                    'account'      => $row['account'],
                    'account_type' => $row['account_type'],
                    'debit'        => $this->fmt($row['debit']),
                    'credit'       => $this->fmt($row['credit']),
                    'net'          => $this->fmt(abs($net)),
                    'net_dr'       => $net >= 0,
                ];
            })
            ->sortBy('account_code')
            ->values();
    }

    // ── 3. Profit & Loss ──────────────────────────────────────────────
    private function profitLoss(string $from, string $to): array
    {
        $trial = $this->trialBalance($from, $to);

        $revenue = $trial->whereIn('account_type', ['revenue'])
            ->sum(fn($r) => $this->unformat($r['credit']) - $this->unformat($r['debit']));

        $cogs = $trial->whereIn('account_type', ['cogs'])
            ->sum(fn($r) => $this->unformat($r['debit']) - $this->unformat($r['credit']));

        $expenses = $trial->whereIn('account_type', ['expense'])
            ->sum(fn($r) => $this->unformat($r['debit']) - $this->unformat($r['credit']));

        $grossProfit = $revenue - $cogs;
        $netProfit   = $grossProfit - $expenses;

        $rows = [];

        $rows[] = ['particulars' => '── REVENUE ──', 'amount' => '', 'section' => 'header'];
        foreach ($trial->whereIn('account_type', ['revenue']) as $r) {
            $amt = $this->unformat($r['credit']) - $this->unformat($r['debit']);
            if ($amt != 0) {
                $rows[] = ['particulars' => '  ' . $r['account'], 'amount' => $this->fmt($amt), 'section' => 'revenue'];
            }
        }
        $rows[] = ['particulars' => 'Total Revenue', 'amount' => $this->fmt($revenue), 'section' => 'subtotal'];

        $rows[] = ['particulars' => '── COST OF GOODS SOLD ──', 'amount' => '', 'section' => 'header'];
        foreach ($trial->whereIn('account_type', ['cogs']) as $r) {
            $amt = $this->unformat($r['debit']) - $this->unformat($r['credit']);
            if ($amt != 0) {
                $rows[] = ['particulars' => '  ' . $r['account'], 'amount' => $this->fmt($amt), 'section' => 'cogs'];
            }
        }
        $rows[] = ['particulars' => 'Total COGS', 'amount' => $this->fmt($cogs), 'section' => 'subtotal'];

        $rows[] = ['particulars' => 'GROSS PROFIT', 'amount' => $this->fmt($grossProfit), 'section' => 'gross'];

        $rows[] = ['particulars' => '── OPERATING EXPENSES ──', 'amount' => '', 'section' => 'header'];
        foreach ($trial->whereIn('account_type', ['expense']) as $r) {
            $amt = $this->unformat($r['debit']) - $this->unformat($r['credit']);
            if ($amt != 0) {
                $rows[] = ['particulars' => '  ' . $r['account'], 'amount' => $this->fmt($amt), 'section' => 'expense'];
            }
        }
        $rows[] = ['particulars' => 'Total Expenses', 'amount' => $this->fmt($expenses), 'section' => 'subtotal'];

        $rows[] = ['particulars' => 'NET PROFIT / (LOSS)', 'amount' => $this->fmt($netProfit), 'section' => 'net'];

        return $rows;
    }

    // ── 4. Balance Sheet ──────────────────────────────────────────────
    private function balanceSheet(string $from, string $to): array
    {
        $trial = $this->trialBalance($from, $to);

        $assetTypes     = ['asset', 'cash', 'bank', 'customer'];
        $liabilityTypes = ['liability', 'vendor'];
        $equityTypes    = ['equity'];

        $assets = $trial->whereIn('account_type', $assetTypes)
            ->map(fn($r) => [
                'name'   => $r['account'],
                'amount' => $this->fmt(
                    $this->unformat($r['debit']) - $this->unformat($r['credit'])
                ),
            ])->filter(fn($r) => $this->unformat($r['amount']) != 0)->values();

        $liabilities = $trial->whereIn('account_type', $liabilityTypes)
            ->map(fn($r) => [
                'name'   => $r['account'],
                'amount' => $this->fmt(
                    $this->unformat($r['credit']) - $this->unformat($r['debit'])
                ),
            ])->filter(fn($r) => $this->unformat($r['amount']) != 0)->values();

        $equity = $trial->whereIn('account_type', $equityTypes)
            ->map(fn($r) => [
                'name'   => $r['account'],
                'amount' => $this->fmt(
                    $this->unformat($r['credit']) - $this->unformat($r['debit'])
                ),
            ])->filter(fn($r) => $this->unformat($r['amount']) != 0)->values();

        $plData    = $this->profitLoss($from, $to);
        $netProfit = collect($plData)->firstWhere('section', 'net');
        if ($netProfit && $this->unformat($netProfit['amount']) != 0) {
            $equity->push(['name' => 'Net Profit / (Loss)', 'amount' => $netProfit['amount']]);
        }

        $liabsAndEquity = $liabilities->concat($equity)->values();

        $totalAssets = $assets->sum(fn($r) => $this->unformat($r['amount']));
        $totalLiabEq = $liabsAndEquity->sum(fn($r) => $this->unformat($r['amount']));

        $max  = max($assets->count(), $liabsAndEquity->count(), 1);
        $rows = [];

        for ($i = 0; $i < $max; $i++) {
            $rows[] = [
                'asset'     => $assets[$i]['name']           ?? '',
                'asset_amt' => $assets[$i]['amount']         ?? '',
                'liab'      => $liabsAndEquity[$i]['name']   ?? '',
                'liab_amt'  => $liabsAndEquity[$i]['amount'] ?? '',
            ];
        }

        $rows[] = [
            'asset'     => 'Total Assets',
            'asset_amt' => $this->fmt($totalAssets),
            'liab'      => 'Total Liabilities & Equity',
            'liab_amt'  => $this->fmt($totalLiabEq),
        ];

        return $rows;
    }

    // ── 5. Party Ledger ───────────────────────────────────────────────
    private function partyLedger(string $from, string $to, ?int $accountId = null): \Illuminate\Support\Collection
    {
        $partyAccountIds = ChartOfAccounts::whereIn('account_type', ['customer', 'vendor'])->pluck('id');

        $query = Voucher::with(['debitAccount', 'creditAccount'])
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->orderBy('id');

        if ($accountId) {
            $query->where(fn($q) => $q->where('ac_dr_sid', $accountId)
                                      ->orWhere('ac_cr_sid', $accountId));
        } else {
            $query->where(fn($q) => $q->whereIn('ac_dr_sid', $partyAccountIds)
                                      ->orWhereIn('ac_cr_sid', $partyAccountIds));
        }

        $rows = $query->get()->map(function ($v) use ($accountId, $partyAccountIds) {
            if ($accountId) {
                $resolvedId = $accountId;
            } else {
                $resolvedId = $partyAccountIds->contains($v->ac_dr_sid)
                    ? $v->ac_dr_sid
                    : $v->ac_cr_sid;
            }

            $isDebit = $v->ac_dr_sid == $resolvedId;
            $party   = $isDebit
                ? ($v->debitAccount->name  ?? 'N/A')
                : ($v->creditAccount->name ?? 'N/A');

            return [
                'date'       => $v->date,
                'party'      => $party,
                'voucher'    => $this->voucherLabel($v),
                'debit'      => $isDebit  ? $this->fmt($v->amount) : '0.00',
                'credit'     => !$isDebit ? $this->fmt($v->amount) : '0.00',
                'balance'    => '0.00',
                'balance_dr' => true,
            ];
        })->toArray();

        // Only meaningful to prepend an opening balance when viewing a single account
        $openingBalance = 0;
        if ($accountId) {
            $openingBalance = $this->partyOpeningBalanceById($accountId);

            if ($openingBalance != 0) {
                $account = ChartOfAccounts::find($accountId);
                array_unshift($rows, [
                    'date'       => $from,
                    'party'      => $account->name ?? '-',
                    'voucher'    => 'Opening Balance',
                    'debit'      => $openingBalance > 0 ? $this->fmt($openingBalance) : '0.00',
                    'credit'     => $openingBalance < 0 ? $this->fmt(abs($openingBalance)) : '0.00',
                    'balance'    => '0.00',
                    'balance_dr' => true,
                ]);
                $openingBalance = 0; // already injected as a row; don't double-count in runningBalance seed
            }
        }

        return collect($this->runningBalance($rows, $openingBalance));
    }

    // ── 6. Receivables ────────────────────────────────────────────────
    private function receivables(string $from, string $to): \Illuminate\Support\Collection
    {
        $accounts = ChartOfAccounts::whereIn('account_type', ['customer', 'vendor'])
            ->get(['id', 'name', 'account_type', 'receivables', 'payables']);

        return $accounts->map(function ($account) use ($from, $to) {
            $totalDebit  = (float) Voucher::where('ac_dr_sid', $account->id)
                ->whereBetween('date', [$from, $to])
                ->whereNull('deleted_at')
                ->sum('amount');
            $totalCredit = (float) Voucher::where('ac_cr_sid', $account->id)
                ->whereBetween('date', [$from, $to])
                ->whereNull('deleted_at')
                ->sum('amount');

            $opening = $this->partyOpeningBalance($account);
            $balance = $opening + $totalDebit - $totalCredit;

            // Only show net debit balance = they owe us
            if ($balance <= 0) return null;

            $label = $account->name;
            if ($account->account_type === 'vendor') {
                $label .= ' (Vendor — Leather Sale)';
            }

            return [
                'customer'         => $label,
                'total_receivable' => $this->fmt($balance),
                '0_30'             => $this->fmt($this->agingBucket($account->id, $to, 0,  30,   'debit')),
                '31_60'            => $this->fmt($this->agingBucket($account->id, $to, 31, 60,   'debit')),
                '61_90'            => $this->fmt($this->agingBucket($account->id, $to, 61, 90,   'debit')),
                'over_90'          => $this->fmt(
                    $this->agingBucket($account->id, $to, 91, null, 'debit')
                    + max(0, $opening) // opening receivable falls into >90 days bucket
                ),
            ];
        })->filter()->values();
    }

    // ── 7. Payables ───────────────────────────────────────────────────
    private function payables(string $from, string $to): \Illuminate\Support\Collection
    {
        $accounts = ChartOfAccounts::whereIn('account_type', ['vendor', 'customer'])
            ->get(['id', 'name', 'account_type', 'receivables', 'payables']);

        return $accounts->map(function ($account) use ($from, $to) {
            $totalDebit  = (float) Voucher::where('ac_dr_sid', $account->id)
                ->whereBetween('date', [$from, $to])
                ->whereNull('deleted_at')
                ->sum('amount');
            $totalCredit = (float) Voucher::where('ac_cr_sid', $account->id)
                ->whereBetween('date', [$from, $to])
                ->whereNull('deleted_at')
                ->sum('amount');

            $opening = $this->partyOpeningBalance($account); // positive = DR, negative = CR
            $balance = $totalCredit - $totalDebit - $opening;

            // Only show net credit balance = we owe them
            if ($balance <= 0) return null;

            $label = $account->name;
            if ($account->account_type === 'customer') {
                $label .= ' (Customer — Advance)';
            }

            return [
                'vendor'        => $label,
                'total_payable' => $this->fmt($balance),
                '0_30'          => $this->fmt($this->agingBucket($account->id, $to, 0,  30,   'credit')),
                '31_60'         => $this->fmt($this->agingBucket($account->id, $to, 31, 60,   'credit')),
                '61_90'         => $this->fmt($this->agingBucket($account->id, $to, 61, 90,   'credit')),
                'over_90'       => $this->fmt(
                    $this->agingBucket($account->id, $to, 91, null, 'credit')
                    + max(0, -$opening) // opening payable falls into >90 days bucket
                ),
            ];
        })->filter()->values();
    }

    private function agingBucket(int $accountId, string $toDate, int $daysFrom, ?int $daysTo, string $side): float
    {
        $end   = Carbon::parse($toDate)->subDays($daysFrom);
        $start = $daysTo ? Carbon::parse($toDate)->subDays($daysTo) : null;

        $col = $side === 'debit' ? 'ac_dr_sid' : 'ac_cr_sid';

        $q = Voucher::where($col, $accountId)
                    ->where('date', '<=', $end)
                    ->whereNull('deleted_at');

        if ($start) $q->where('date', '>=', $start);

        return (float) $q->sum('amount');
    }

    // ── 8. Cash Book ─────────────────────────────────────────────────
    private function cashBook(string $from, string $to): array
    {
        $cashIds = ChartOfAccounts::where('account_type', 'cash')->pluck('id');

        $rows = Voucher::whereBetween('date', [$from, $to])
            ->where(fn($q) => $q->whereIn('ac_dr_sid', $cashIds)
                                ->orWhereIn('ac_cr_sid', $cashIds))
            ->with(['debitAccount', 'creditAccount'])
            ->whereNull('deleted_at')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function ($v) use ($cashIds) {
                $isDebit = $cashIds->contains($v->ac_dr_sid);
                $contra  = $isDebit
                    ? ($v->creditAccount->name ?? '-')
                    : ($v->debitAccount->name  ?? '-');

                return [
                    'date'        => $v->date,
                    'particulars' => $this->voucherLabel($v) . ' | ' . $contra,
                    'debit'       => $isDebit  ? $this->fmt($v->amount) : '0.00',
                    'credit'      => !$isDebit ? $this->fmt($v->amount) : '0.00',
                    'balance'     => '0.00',
                    'balance_dr'  => true,
                ];
            })->toArray();

        return $this->runningBalance($rows);
    }

    // ── 9. Bank Book ─────────────────────────────────────────────────
    private function bankBook(string $from, string $to): array
    {
        $bankIds = ChartOfAccounts::where('account_type', 'bank')->pluck('id');

        $rows = Voucher::whereBetween('date', [$from, $to])
            ->where(fn($q) => $q->whereIn('ac_dr_sid', $bankIds)
                                ->orWhereIn('ac_cr_sid', $bankIds))
            ->with(['debitAccount', 'creditAccount'])
            ->whereNull('deleted_at')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function ($v) use ($bankIds) {
                $isDebit = $bankIds->contains($v->ac_dr_sid);
                $contra  = $isDebit
                    ? ($v->creditAccount->name ?? '-')
                    : ($v->debitAccount->name  ?? '-');

                return [
                    'date'       => $v->date,
                    'bank'       => $this->voucherLabel($v) . ' | ' . $contra,
                    'debit'      => $isDebit  ? $this->fmt($v->amount) : '0.00',
                    'credit'     => !$isDebit ? $this->fmt($v->amount) : '0.00',
                    'balance'    => '0.00',
                    'balance_dr' => true,
                ];
            })->toArray();

        return $this->runningBalance($rows);
    }

    // ── 10. Journal / Day Book ────────────────────────────────────────
    private function journalBook(string $from, string $to): \Illuminate\Support\Collection
    {
        return Voucher::with(['debitAccount', 'creditAccount'])
            ->whereBetween('date', [$from, $to])
            ->whereNull('deleted_at')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function ($v) {
                return [
                    'date'       => $v->date,
                    'voucher'    => $this->voucherLabel($v),
                    'dr_account' => ($v->debitAccount->account_code  ?? '')
                        . ' — ' . ($v->debitAccount->name  ?? '-'),
                    'cr_account' => ($v->creditAccount->account_code ?? '')
                        . ' — ' . ($v->creditAccount->name ?? '-'),
                    'amount'     => $this->fmt($v->amount),
                    'remarks'    => $v->remarks ?? '',
                ];
            });
    }

    // ── 11. Expense Analysis ──────────────────────────────────────────
    private function expenseAnalysis(string $from, string $to): \Illuminate\Support\Collection
    {
        $trial = $this->trialBalance($from, $to);

        return $trial->whereIn('account_type', ['expense', 'cogs'])
            ->map(fn($r) => [
                'expense_head' => $r['account_code'] . ' — ' . $r['account'],
                'account_type' => $r['account_type'],
                'amount'       => $this->fmt(
                    $this->unformat($r['debit']) - $this->unformat($r['credit'])
                ),
            ])
            ->filter(fn($r) => $this->unformat($r['amount']) > 0)
            ->sortBy('expense_head')
            ->values();
    }

    // ── 12. Cash Flow ─────────────────────────────────────────────────
    private function cashFlow(string $from, string $to): array
    {
        $cashBankIds = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->pluck('id');

        $operatingIn  = (float) Voucher::whereBetween('date', [$from, $to])
            ->whereIn('ac_dr_sid', $cashBankIds)
            ->whereIn('voucher_type', ['sale', 'receipt'])
            ->whereNull('deleted_at')
            ->sum('amount');

        $operatingOut = (float) Voucher::whereBetween('date', [$from, $to])
            ->whereIn('ac_cr_sid', $cashBankIds)
            ->whereIn('voucher_type', ['purchase', 'payment', 'purchase_return'])
            ->whereNull('deleted_at')
            ->sum('amount');

        $productionOut = (float) Voucher::whereBetween('date', [$from, $to])
            ->whereIn('ac_cr_sid', $cashBankIds)
            ->whereIn('voucher_type', ['production_receiving', 'production_return'])
            ->whereNull('deleted_at')
            ->sum('amount');

        $productionIn = (float) Voucher::whereBetween('date', [$from, $to])
            ->whereIn('ac_dr_sid', $cashBankIds)
            ->whereIn('voucher_type', ['production_return'])
            ->whereNull('deleted_at')
            ->sum('amount');

        $totalIn  = (float) Voucher::whereBetween('date', [$from, $to])
            ->whereIn('ac_dr_sid', $cashBankIds)
            ->whereNull('deleted_at')
            ->sum('amount');

        $totalOut = (float) Voucher::whereBetween('date', [$from, $to])
            ->whereIn('ac_cr_sid', $cashBankIds)
            ->whereNull('deleted_at')
            ->sum('amount');

        return [
            [
                'activity' => 'Operating — Sales & Receipts',
                'inflows'  => $this->fmt($operatingIn),
                'outflows' => '0.00',
                'net flow' => $this->fmt($operatingIn),
            ],
            [
                'activity' => 'Operating — Purchases & Payments',
                'inflows'  => '0.00',
                'outflows' => $this->fmt($operatingOut),
                'net flow' => $this->fmt(-$operatingOut),
            ],
            [
                'activity' => 'Production — CMT Payments & Receivings',
                'inflows'  => $this->fmt($productionIn),
                'outflows' => $this->fmt($productionOut),
                'net flow' => $this->fmt($productionIn - $productionOut),
            ],
            [
                'activity' => 'Other Cash / Bank Flows',
                'inflows'  => $this->fmt($totalIn  - $operatingIn  - $productionIn),
                'outflows' => $this->fmt($totalOut - $operatingOut - $productionOut),
                'net flow' => $this->fmt(
                    ($totalIn - $operatingIn - $productionIn) -
                    ($totalOut - $operatingOut - $productionOut)
                ),
            ],
            [
                'activity' => 'NET CASH FLOW',
                'inflows'  => $this->fmt($totalIn),
                'outflows' => $this->fmt($totalOut),
                'net flow' => $this->fmt($totalIn - $totalOut),
            ],
        ];
    }
}