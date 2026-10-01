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
    // account_type groups (COA form uses 'expenses', seeder uses 'expense' — both count)
    private const ASSET_TYPES     = ['asset', 'cash', 'bank', 'customer', 'inventory', 'receivable'];
    private const LIABILITY_TYPES = ['liability', 'vendor', 'payable'];
    private const EQUITY_TYPES    = ['equity'];
    private const REVENUE_TYPES   = ['revenue'];
    private const COGS_TYPES      = ['cogs'];
    private const EXPENSE_TYPES   = ['expense', 'expenses'];

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
            'trial_balance'    => $this->trialBalance(null, $to),   // as of To date
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

    /**
     * Balance brought forward at the start of $from (signed, + = DR):
     * the account's opening balance from COA + every voucher dated before $from.
     */
    private function balanceBefore(int|array $accountIds, string $from): float
    {
        $ids = (array) $accountIds;
        $opening = ChartOfAccounts::whereIn('id', $ids)->get()->sum(fn ($a) => $this->partyOpeningBalance($a));
        $dr = (float) Voucher::whereIn('ac_dr_sid', $ids)->where('date', '<', $from)->sum('amount');
        $cr = (float) Voucher::whereIn('ac_cr_sid', $ids)->where('date', '<', $from)->sum('amount');
        return $opening + $dr - $cr;
    }

    private function openingRow(float $balance, string $from, array $extra): array
    {
        return array_merge([
            'date'       => $from,
            'debit'      => $balance > 0 ? $this->fmt($balance) : '0.00',
            'credit'     => $balance < 0 ? $this->fmt(abs($balance)) : '0.00',
            'balance'    => '0.00',
            'balance_dr' => true,
        ], $extra);
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
            'production_receiving' => 'FG Receiving (CMT)',
            'production_return'    => 'Production Return',
            'production_wastage'   => 'Wastage Return',
            'receipt'              => 'Receipt',
            'payment'              => 'Payment',
            'journal'              => 'Journal',
            'contra'               => 'Contra',
            'stock_transfer'       => 'Stock Movement',
            'stock_adjustment'     => 'Stock Adjustment',
            'pdc'                  => 'PDC Cheque',
        ];

        $label   = $typeMap[$v->voucher_type] ?? ucwords(str_replace('_', ' ', $v->voucher_type));
        $refId   = $this->documentNo($v) ?? ($v->source_id ?? $v->id);
        $remarks = $v->remarks ? ' — ' . Str::limit($v->remarks, 50) : '';

        return $label . ' ' . (str_contains((string) $refId, '-') ? $refId : '#' . $refId) . $remarks;
    }

    /** Document number of the voucher's source (PUR-00001, GRN-00001, SI-00001 …), cached per request. */
    private function documentNo(Voucher $v): ?string
    {
        static $cache = [];
        if (!$v->source_type || !$v->source_id) return null;
        $cols = [
            \App\Models\PurchaseInvoice::class     => 'invoice_no',
            \App\Models\PurchaseReturn::class      => 'return_no',
            \App\Models\ProductionReceiving::class => 'grn_no',
            \App\Models\SaleInvoice::class         => 'invoice_no',
            \App\Models\StockAdjustment::class     => 'adj_no',
            \App\Models\PdcCheque::class           => 'pdc_no',
        ];
        $col = $cols[$v->source_type] ?? null;
        if (!$col) return null;
        $key = $v->source_type . '#' . $v->source_id;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = \Illuminate\Support\Facades\DB::table((new $v->source_type)->getTable())->where('id', $v->source_id)->value($col);
        }
        return $cache[$key];
    }

    // ── 1. General Ledger ─────────────────────────────────────────────
    private function generalLedger(?int $accountId, string $from, string $to): array
    {
        if (!$accountId) return [];

        $openingBalance = $this->balanceBefore($accountId, $from);

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

        // Opening = COA opening balance + everything before the From date
        if (round($openingBalance, 2) != 0) {
            array_unshift($rows, $this->openingRow($openingBalance, $from, ['voucher' => 'Opening Balance (b/f)', 'account' => '-']));
        }

        return $this->runningBalance($rows);
    }

    // ── 2. Trial Balance ──────────────────────────────────────────────
    /**
     * Trial balance as of $to (all history incl. opening balances).
     * Pass $from to get movement for a period only (used by Profit & Loss).
     */
    private function trialBalance(?string $from, string $to, bool $withOpening = true): \Illuminate\Support\Collection
    {
        $debits = DB::table('vouchers')
            ->join('chart_of_accounts as coa', 'vouchers.ac_dr_sid', '=', 'coa.id')
            ->when($from, fn ($q) => $q->where('vouchers.date', '>=', $from))
            ->where('vouchers.date', '<=', $to)
            ->whereNull('vouchers.deleted_at')
            ->select(
                'coa.id', 'coa.account_code', 'coa.name', 'coa.account_type',
                DB::raw('SUM(vouchers.amount) as total_debit'),
                DB::raw('0 as total_credit')
            )
            ->groupBy('coa.id', 'coa.account_code', 'coa.name', 'coa.account_type');

        $credits = DB::table('vouchers')
            ->join('chart_of_accounts as coa', 'vouchers.ac_cr_sid', '=', 'coa.id')
            ->when($from, fn ($q) => $q->where('vouchers.date', '>=', $from))
            ->where('vouchers.date', '<=', $to)
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
        $partyAccounts = $withOpening ? ChartOfAccounts::whereIn('account_type', ['customer', 'vendor'])->get() : collect();

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

        // Party opening balances are entered one-sided on COA — put the other side in an
        // "Opening Balances (parties)" equity line so the trial balance balances.
        $partyOpening = $partyAccounts->sum(fn ($a) => $this->partyOpeningBalance($a));
        if (round($partyOpening, 2) != 0) {
            $voucherTotals->put('opening', [
                'id'           => 0,
                'account_code' => '3-OPEN',
                'account'      => 'Opening Balances (parties)',
                'account_type' => 'equity',
                'debit'        => $partyOpening < 0 ? abs($partyOpening) : 0,
                'credit'       => $partyOpening > 0 ? $partyOpening : 0,
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
    private function profitLoss(?string $from, string $to): array
    {
        $trial = $this->trialBalance($from, $to, false);

        $revenue = $trial->whereIn('account_type', self::REVENUE_TYPES)
            ->sum(fn($r) => $this->unformat($r['credit']) - $this->unformat($r['debit']));

        $cogs = $trial->whereIn('account_type', self::COGS_TYPES)
            ->sum(fn($r) => $this->unformat($r['debit']) - $this->unformat($r['credit']));

        $expenses = $trial->whereIn('account_type', self::EXPENSE_TYPES)
            ->sum(fn($r) => $this->unformat($r['debit']) - $this->unformat($r['credit']));

        $grossProfit = $revenue - $cogs;
        $netProfit   = $grossProfit - $expenses;

        $rows = [];

        $rows[] = ['particulars' => '── REVENUE ──', 'amount' => '', 'section' => 'header'];
        foreach ($trial->whereIn('account_type', self::REVENUE_TYPES) as $r) {
            $amt = $this->unformat($r['credit']) - $this->unformat($r['debit']);
            if ($amt != 0) {
                $rows[] = ['particulars' => '  ' . $r['account'], 'amount' => $this->fmt($amt), 'section' => 'revenue'];
            }
        }
        $rows[] = ['particulars' => 'Total Revenue', 'amount' => $this->fmt($revenue), 'section' => 'subtotal'];

        $rows[] = ['particulars' => '── COST OF GOODS SOLD ──', 'amount' => '', 'section' => 'header'];
        foreach ($trial->whereIn('account_type', self::COGS_TYPES) as $r) {
            $amt = $this->unformat($r['debit']) - $this->unformat($r['credit']);
            if ($amt != 0) {
                $rows[] = ['particulars' => '  ' . $r['account'], 'amount' => $this->fmt($amt), 'section' => 'cogs'];
            }
        }
        $rows[] = ['particulars' => 'Total COGS', 'amount' => $this->fmt($cogs), 'section' => 'subtotal'];

        $rows[] = ['particulars' => 'GROSS PROFIT', 'amount' => $this->fmt($grossProfit), 'section' => 'gross'];

        $rows[] = ['particulars' => '── OPERATING EXPENSES ──', 'amount' => '', 'section' => 'header'];
        foreach ($trial->whereIn('account_type', self::EXPENSE_TYPES) as $r) {
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
        // Balance sheet is a position AS OF $to — cumulative, not just the period
        $trial = $this->trialBalance(null, $to);

        $assetTypes     = self::ASSET_TYPES;
        $liabilityTypes = self::LIABILITY_TYPES;
        $equityTypes    = self::EQUITY_TYPES;

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

        // Accumulated profit up to $to
        $plData    = $this->profitLoss(null, $to);
        $netProfit = collect($plData)->firstWhere('section', 'net');
        if ($netProfit && $this->unformat($netProfit['amount']) != 0) {
            $equity->push(['name' => 'Profit / (Loss) to date', 'amount' => $netProfit['amount']]);
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
            $openingBalance = $this->balanceBefore($accountId, $from);

            if (round($openingBalance, 2) != 0) {
                $account = ChartOfAccounts::find($accountId);
                array_unshift($rows, $this->openingRow($openingBalance, $from, ['party' => $account->name ?? '-', 'voucher' => 'Opening Balance (b/f)']));
            }
            $openingBalance = 0; // injected as a row
        }

        return collect($this->runningBalance($rows, $openingBalance));
    }

    // ── 6. Receivables ────────────────────────────────────────────────
    private function receivables(string $from, string $to): \Illuminate\Support\Collection
    {
        $accounts = ChartOfAccounts::whereIn('account_type', ['customer', 'vendor'])
            ->get(['id', 'name', 'account_type', 'receivables', 'payables']);

        return $accounts->map(function ($account) use ($from, $to) {
            // outstanding AS OF $to: opening + every voucher up to $to
            $totalDebit  = (float) Voucher::where('ac_dr_sid', $account->id)->where('date', '<=', $to)->sum('amount');
            $totalCredit = (float) Voucher::where('ac_cr_sid', $account->id)->where('date', '<=', $to)->sum('amount');

            $opening = $this->partyOpeningBalance($account);
            $balance = $opening + $totalDebit - $totalCredit;

            // Only show net debit balance = they owe us
            if ($balance <= 0) return null;

            $label = $account->name;
            if ($account->account_type === 'vendor') {
                $label .= ' (Vendor — debit balance / advance)';
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
            // outstanding AS OF $to: opening + every voucher up to $to
            $totalDebit  = (float) Voucher::where('ac_dr_sid', $account->id)->where('date', '<=', $to)->sum('amount');
            $totalCredit = (float) Voucher::where('ac_cr_sid', $account->id)->where('date', '<=', $to)->sum('amount');

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

        $opening = $this->balanceBefore($cashIds->all(), $from);
        if (round($opening, 2) != 0) {
            array_unshift($rows, $this->openingRow($opening, $from, ['particulars' => 'Opening Balance (b/f)']));
        }

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

        $opening = $this->balanceBefore($bankIds->all(), $from);
        if (round($opening, 2) != 0) {
            array_unshift($rows, $this->openingRow($opening, $from, ['bank' => 'Opening Balance (b/f)']));
        }

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
        $trial = $this->trialBalance($from, $to, false);

        return $trial->whereIn('account_type', array_merge(self::EXPENSE_TYPES, self::COGS_TYPES))
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
            ->whereIn('voucher_type', ['purchase', 'payment', 'purchase_return', 'pdc'])
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
                'activity' => 'Operating — Purchases, Payments & Cleared PDCs',
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