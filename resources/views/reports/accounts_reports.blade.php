@extends('layouts.app')
@section('title', 'Accounting Reports')

@section('content')

@php
  $tabs = [
    'general_ledger'   => ['icon' => 'fa-book',          'label' => 'General Ledger'],
    'trial_balance'    => ['icon' => 'fa-balance-scale',  'label' => 'Trial Balance'],
    'profit_loss'      => ['icon' => 'fa-chart-line',     'label' => 'Profit & Loss'],
    'balance_sheet'    => ['icon' => 'fa-landmark',       'label' => 'Balance Sheet'],
    'party_ledger'     => ['icon' => 'fa-users',          'label' => 'Party Ledger'],
    'receivables'      => ['icon' => 'fa-arrow-down',     'label' => 'Receivables'],
    'payables'         => ['icon' => 'fa-arrow-up',       'label' => 'Payables'],
    'cash_book'        => ['icon' => 'fa-money-bill',     'label' => 'Cash Book'],
    'bank_book'        => ['icon' => 'fa-university',     'label' => 'Bank Book'],
    'journal_book'     => ['icon' => 'fa-list-alt',       'label' => 'Day Book'],
    'expense_analysis' => ['icon' => 'fa-receipt',        'label' => 'Expenses'],
    'cash_flow'        => ['icon' => 'fa-water',          'label' => 'Cash Flow'],
  ];
@endphp

<div class="tabs">

  <ul class="nav nav-tabs flex-wrap">
    @foreach($tabs as $key => $meta)
      <li class="nav-item">
        <a class="nav-link {{ $key === $report ? 'active' : '' }}"
           href="{{ route('reports.accounts') }}?report={{ $key }}&from_date={{ $from }}&to_date={{ $to }}">
          <i class="fas {{ $meta['icon'] }} me-1"></i>
          {{ $meta['label'] }}
        </a>
      </li>
    @endforeach
  </ul>

  <div class="tab-content mt-3">

    {{-- ── Filter Form ──────────────────────────────────────────── --}}
    <form method="GET" action="{{ route('reports.accounts') }}" class="row g-2 mb-3">
      <input type="hidden" name="report" value="{{ $report }}">

      <div class="col-md-2">
        <label class="form-label small text-muted">From Date</label>
        <input type="date" name="from_date" value="{{ $from }}" class="form-control" required>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted">To Date</label>
        <input type="date" name="to_date" value="{{ $to }}" class="form-control" required>
      </div>

      @if(in_array($report, ['general_ledger', 'party_ledger']))
        <div class="col-md-4">
          <label class="form-label small text-muted">Account</label>
          <select name="account_id" class="form-control select2-js">
            <option value="">-- All Accounts --</option>
            @foreach($chartOfAccounts as $coa)
              <option value="{{ $coa->id }}"
                {{ $accountId == $coa->id ? 'selected' : '' }}>
                {{ $coa->account_code }} — {{ $coa->name }}
              </option>
            @endforeach
          </select>
        </div>
      @endif

      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100" type="submit">
          <i class="fas fa-filter me-1"></i> Filter
        </button>
      </div>
    </form>

    {{-- ── 1. GENERAL LEDGER ───────────────────────────────────── --}}
    @if($report === 'general_ledger')
      @if(!$accountId)
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-1"></i>
          Please select an account from the filter above to view the General Ledger.
        </div>
      @elseif(count($reportData) === 0)
        <div class="alert alert-warning">No transactions found for the selected account and date range.</div>
      @else
        @php
          $totalDr  = collect($reportData)->sum(fn($r) => (float) str_replace(',', '', $r['debit']));
          $totalCr  = collect($reportData)->sum(fn($r) => (float) str_replace(',', '', $r['credit']));
          $lastBal  = end($reportData);
        @endphp
        <div class="row mb-2 text-center">
          <div class="col-md-4">
            <div class="border rounded p-2 bg-light">
              <small class="text-muted d-block">Total Debit</small>
              <strong class="text-success">{{ number_format($totalDr, 2) }}</strong>
            </div>
          </div>
          <div class="col-md-4">
            <div class="border rounded p-2 bg-light">
              <small class="text-muted d-block">Total Credit</small>
              <strong class="text-danger">{{ number_format($totalCr, 2) }}</strong>
            </div>
          </div>
          <div class="col-md-4">
            <div class="border rounded p-2 bg-light">
              <small class="text-muted d-block">Closing Balance</small>
              <strong class="{{ ($lastBal['balance_dr'] ?? true) ? 'text-success' : 'text-danger' }}">
                {{ $lastBal['balance'] ?? '0.00' }}
                {{ ($lastBal['balance_dr'] ?? true) ? 'DR' : 'CR' }}
              </strong>
            </div>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-sm table-striped" id="glTable">
            <thead class="table-light">
              <tr>
                <th>Date</th>
                <th>Voucher / Ref</th>
                <th>Contra Account</th>
                <th class="text-end">Debit</th>
                <th class="text-end">Credit</th>
                <th class="text-end">Balance</th>
              </tr>
            </thead>
            <tbody>
              @foreach($reportData as $row)
                <tr>
                  <td>{{ \Carbon\Carbon::parse($row['date'])->format('d-M-Y') }}</td>
                  <td><small>{{ $row['voucher'] }}</small></td>
                  <td>{{ $row['account'] }}</td>
                  <td class="text-end text-success">
                    {{ $row['debit'] !== '0.00' ? $row['debit'] : '—' }}
                  </td>
                  <td class="text-end text-danger">
                    {{ $row['credit'] !== '0.00' ? $row['credit'] : '—' }}
                  </td>
                  <td class="text-end fw-bold {{ ($row['balance_dr'] ?? true) ? 'text-primary' : 'text-danger' }}">
                    {{ $row['balance'] }}
                    <small class="text-muted">{{ ($row['balance_dr'] ?? true) ? 'DR' : 'CR' }}</small>
                  </td>
                </tr>
              @endforeach
            </tbody>
            <tfoot class="table-light fw-bold">
              <tr>
                <td colspan="3" class="text-end">Totals</td>
                <td class="text-end text-success">{{ number_format($totalDr, 2) }}</td>
                <td class="text-end text-danger">{{ number_format($totalCr, 2) }}</td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      @endif
    @endif

    {{-- ── 2. TRIAL BALANCE ────────────────────────────────────── --}}
    @if($report === 'trial_balance')
      @php
        $tbData    = collect($reportData);
        $totalDr   = $tbData->sum(fn($r) => (float) str_replace(',', '', $r['debit']));
        $totalCr   = $tbData->sum(fn($r) => (float) str_replace(',', '', $r['credit']));
        $balanced  = abs($totalDr - $totalCr) < 0.01;
      @endphp

      @if(!$balanced)
        <div class="alert alert-danger">
          <i class="fas fa-exclamation-triangle me-1"></i>
          Trial balance is <strong>not balanced</strong>!
          DR: {{ number_format($totalDr, 2) }} | CR: {{ number_format($totalCr, 2) }}
          | Difference: {{ number_format(abs($totalDr - $totalCr), 2) }}
        </div>
      @else
        <div class="alert alert-success">
          <i class="fas fa-check-circle me-1"></i>
          Trial balance is balanced. Total: {{ number_format($totalDr, 2) }}
        </div>
      @endif

      <div class="table-responsive">
        <table class="table table-bordered table-sm table-striped" id="tbTable">
          <thead class="table-light">
            <tr>
              <th>Code</th>
              <th>Account</th>
              <th>Type</th>
              <th class="text-end">Debit</th>
              <th class="text-end">Credit</th>
              <th class="text-end">Balance</th>
            </tr>
          </thead>
          <tbody>
            @forelse($reportData as $row)
              <tr>
                <td><small class="text-muted">{{ $row['account_code'] }}</small></td>
                <td>{{ $row['account'] }}</td>
                <td><span class="badge bg-secondary">{{ $row['account_type'] }}</span></td>
                <td class="text-end">{{ $row['debit'] !== '0.00' ? $row['debit'] : '—' }}</td>
                <td class="text-end">{{ $row['credit'] !== '0.00' ? $row['credit'] : '—' }}</td>
                <td class="text-end fw-bold {{ ($row['net_dr'] ?? true) ? 'text-success' : 'text-danger' }}">
                  {{ $row['net'] }}
                  <small>{{ ($row['net_dr'] ?? true) ? 'DR' : 'CR' }}</small>
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-muted">No data found.</td></tr>
            @endforelse
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr>
              <td colspan="3" class="text-end">Totals</td>
              <td class="text-end">{{ number_format($totalDr, 2) }}</td>
              <td class="text-end">{{ number_format($totalCr, 2) }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    @endif

    {{-- ── 3. PROFIT & LOSS ────────────────────────────────────── --}}
    @if($report === 'profit_loss')
      <div class="table-responsive">
        <table class="table table-bordered table-sm" id="plTable">
          <thead class="table-light">
            <tr>
              <th>Particulars</th>
              <th class="text-end">Amount (PKR)</th>
            </tr>
          </thead>
          <tbody>
            @foreach($reportData as $row)
              @php
                $isHeader   = ($row['section'] ?? '') === 'header';
                $isSubtotal = ($row['section'] ?? '') === 'subtotal';
                $isGross    = ($row['section'] ?? '') === 'gross';
                $isNet      = ($row['section'] ?? '') === 'net';
                $amt        = (float) str_replace(',', '', $row['amount'] ?? '0');
                $rowClass   = $isHeader ? 'table-secondary fw-bold' :
                             ($isSubtotal ? 'table-light fw-bold' :
                             ($isGross ? 'table-info fw-bold' :
                             ($isNet ? ($amt >= 0 ? 'table-success fw-bold' : 'table-danger fw-bold') : '')));
              @endphp
              <tr class="{{ $rowClass }}">
                <td>{{ $row['particulars'] }}</td>
                <td class="text-end">
                  @if($row['amount'] ?? false)
                    <span class="{{ $amt < 0 ? 'text-danger' : '' }}">
                      {{ $amt < 0 ? '(' . number_format(abs($amt), 2) . ')' : $row['amount'] }}
                    </span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif

    {{-- ── 4. BALANCE SHEET ────────────────────────────────────── --}}
    @if($report === 'balance_sheet')
      <div class="table-responsive">
        <table class="table table-bordered table-sm" id="bsTable">
          <thead class="table-light">
            <tr>
              <th class="text-center" colspan="2">Assets</th>
              <th class="text-center" colspan="2">Liabilities & Equity</th>
            </tr>
          </thead>
          <tbody>
            @foreach($reportData as $row)
              @php $isTotal = str_starts_with($row['asset'] ?? '', 'Total'); @endphp
              <tr class="{{ $isTotal ? 'table-light fw-bold' : '' }}">
                <td>{{ $row['asset'] ?? '' }}</td>
                <td class="text-end text-muted">{{ $row['asset_amt'] ?? '' }}</td>
                <td>{{ $row['liab'] ?? '' }}</td>
                <td class="text-end text-muted">{{ $row['liab_amt'] ?? '' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif

    {{-- ── 5. PARTY LEDGER ─────────────────────────────────────── --}}
    @if($report === 'party_ledger')
      @if(!$accountId)
        <div class="alert alert-info">
          Select an account above to view a specific party ledger, or leave blank to see all party transactions.
        </div>
      @endif

      @if(count($reportData))
        @php
          $totalDr = collect($reportData)->sum(fn($r) => (float) str_replace(',', '', $r['debit']));
          $totalCr = collect($reportData)->sum(fn($r) => (float) str_replace(',', '', $r['credit']));
          $lastRow = $reportData instanceof \Illuminate\Support\Collection ? $reportData->last() : end($reportData);
        @endphp

        <div class="table-responsive">
          <table class="table table-bordered table-sm table-striped" id="plrTable">
            <thead class="table-light">
              <tr>
                <th>Date</th>
                <th>Party</th>
                <th>Voucher / Ref</th>
                <th class="text-end">Debit</th>
                <th class="text-end">Credit</th>
                <th class="text-end">Balance</th>
              </tr>
            </thead>
            <tbody>
              @foreach($reportData as $row)
                <tr>
                  <td>{{ \Carbon\Carbon::parse($row['date'])->format('d-M-Y') }}</td>
                  <td>{{ $row['party'] }}</td>
                  <td><small>{{ $row['voucher'] }}</small></td>
                  <td class="text-end text-success">
                    {{ $row['debit'] !== '0.00' ? $row['debit'] : '—' }}
                  </td>
                  <td class="text-end text-danger">
                    {{ $row['credit'] !== '0.00' ? $row['credit'] : '—' }}
                  </td>
                  <td class="text-end fw-bold {{ ($row['balance_dr'] ?? true) ? 'text-primary' : 'text-danger' }}">
                    {{ $row['balance'] }}
                    <small class="text-muted">{{ ($row['balance_dr'] ?? true) ? 'DR' : 'CR' }}</small>
                  </td>
                </tr>
              @endforeach
            </tbody>
            <tfoot class="table-light fw-bold">
              <tr>
                <td colspan="3" class="text-end">Totals</td>
                <td class="text-end text-success">{{ number_format($totalDr, 2) }}</td>
                <td class="text-end text-danger">{{ number_format($totalCr, 2) }}</td>
                <td class="text-end">
                  {{ $lastRow['balance'] ?? '0.00' }}
                  <small>{{ ($lastRow['balance_dr'] ?? true) ? 'DR' : 'CR' }}</small>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      @else
        <div class="alert alert-warning">No party transactions found for the selected period.</div>
      @endif
    @endif

    {{-- ── 6. RECEIVABLES ──────────────────────────────────────── --}}
    @if($report === 'receivables')
      @php $totalRec = collect($reportData)->sum(fn($r) => (float) str_replace(',', '', $r['total_receivable'])); @endphp
      <div class="row mb-3">
        <div class="col text-end">
          Total Receivable: <strong class="text-danger fs-5">PKR {{ number_format($totalRec, 2) }}</strong>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-bordered table-sm table-striped" id="recTable">
          <thead class="table-light">
            <tr>
              <th>Customer / Party</th>
              <th class="text-end">Total Receivable</th>
              <th class="text-end">0–30 Days</th>
              <th class="text-end">31–60 Days</th>
              <th class="text-end">61–90 Days</th>
              <th class="text-end">&gt;90 Days</th>
            </tr>
          </thead>
          <tbody>
            @forelse($reportData as $row)
              <tr>
                <td>{{ $row['customer'] }}</td>
                <td class="text-end fw-bold text-danger">{{ $row['total_receivable'] }}</td>
                <td class="text-end">{{ $row['0_30'] }}</td>
                <td class="text-end">{{ $row['31_60'] }}</td>
                <td class="text-end">{{ $row['61_90'] }}</td>
                <td class="text-end {{ $row['over_90'] !== '0.00' ? 'text-danger fw-bold' : '' }}">
                  {{ $row['over_90'] }}
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-muted">No receivables found.</td></tr>
            @endforelse
          </tbody>
          @if(count($reportData))
            <tfoot class="table-light fw-bold">
              <tr>
                <td class="text-end">Total</td>
                <td class="text-end text-danger">{{ number_format($totalRec, 2) }}</td>
                <td colspan="4"></td>
              </tr>
            </tfoot>
          @endif
        </table>
      </div>
    @endif

    {{-- ── 7. PAYABLES ─────────────────────────────────────────── --}}
    @if($report === 'payables')
      @php $totalPay = collect($reportData)->sum(fn($r) => (float) str_replace(',', '', $r['total_payable'])); @endphp
      <div class="row mb-3">
        <div class="col text-end">
          Total Payable: <strong class="text-danger fs-5">PKR {{ number_format($totalPay, 2) }}</strong>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-bordered table-sm table-striped" id="payTable">
          <thead class="table-light">
            <tr>
              <th>Vendor / Party</th>
              <th class="text-end">Total Payable</th>
              <th class="text-end">0–30 Days</th>
              <th class="text-end">31–60 Days</th>
              <th class="text-end">61–90 Days</th>
              <th class="text-end">&gt;90 Days</th>
            </tr>
          </thead>
          <tbody>
            @forelse($reportData as $row)
              <tr>
                <td>{{ $row['vendor'] }}</td>
                <td class="text-end fw-bold text-danger">{{ $row['total_payable'] }}</td>
                <td class="text-end">{{ $row['0_30'] }}</td>
                <td class="text-end">{{ $row['31_60'] }}</td>
                <td class="text-end">{{ $row['61_90'] }}</td>
                <td class="text-end {{ $row['over_90'] !== '0.00' ? 'text-danger fw-bold' : '' }}">
                  {{ $row['over_90'] }}
                </td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-muted">No payables found.</td></tr>
            @endforelse
          </tbody>
          @if(count($reportData))
            <tfoot class="table-light fw-bold">
              <tr>
                <td class="text-end">Total</td>
                <td class="text-end text-danger">{{ number_format($totalPay, 2) }}</td>
                <td colspan="4"></td>
              </tr>
            </tfoot>
          @endif
        </table>
      </div>
    @endif

    {{-- ── 8. CASH BOOK ─────────────────────────────────────────── --}}
    @if($report === 'cash_book')
      @php
        $totalDr = collect($reportData)->sum(fn($r) => (float) str_replace(',', '', $r['debit']));
        $totalCr = collect($reportData)->sum(fn($r) => (float) str_replace(',', '', $r['credit']));
        $lastRow = end($reportData);
      @endphp
      <div class="row mb-2">
        <div class="col text-end">
          <span class="me-3">Total Receipts: <strong class="text-success">{{ number_format($totalDr, 2) }}</strong></span>
          <span class="me-3">Total Payments: <strong class="text-danger">{{ number_format($totalCr, 2) }}</strong></span>
          <span>Closing Balance: <strong class="text-primary">{{ $lastRow['balance'] ?? '0.00' }}</strong></span>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-bordered table-sm table-striped" id="cbTable">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Particulars</th>
              <th class="text-end">Debit (Receipt)</th>
              <th class="text-end">Credit (Payment)</th>
              <th class="text-end">Balance</th>
            </tr>
          </thead>
          <tbody>
            @forelse($reportData as $row)
              <tr>
                <td>{{ \Carbon\Carbon::parse($row['date'])->format('d-M-Y') }}</td>
                <td><small>{{ $row['particulars'] }}</small></td>
                <td class="text-end text-success">
                  {{ $row['debit'] !== '0.00' ? $row['debit'] : '—' }}
                </td>
                <td class="text-end text-danger">
                  {{ $row['credit'] !== '0.00' ? $row['credit'] : '—' }}
                </td>
                <td class="text-end fw-bold {{ ($row['balance_dr'] ?? true) ? 'text-primary' : 'text-danger' }}">
                  {{ $row['balance'] }}
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-muted">No cash transactions found.</td></tr>
            @endforelse
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr>
              <td colspan="2" class="text-end">Totals</td>
              <td class="text-end text-success">{{ number_format($totalDr, 2) }}</td>
              <td class="text-end text-danger">{{ number_format($totalCr, 2) }}</td>
              <td class="text-end">{{ $lastRow['balance'] ?? '0.00' }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    @endif

    {{-- ── 9. BANK BOOK ─────────────────────────────────────────── --}}
    @if($report === 'bank_book')
      @php
        $totalDr = collect($reportData)->sum(fn($r) => (float) str_replace(',', '', $r['debit']));
        $totalCr = collect($reportData)->sum(fn($r) => (float) str_replace(',', '', $r['credit']));
        $lastRow = end($reportData);
      @endphp
      <div class="row mb-2">
        <div class="col text-end">
          <span class="me-3">Total Deposits: <strong class="text-success">{{ number_format($totalDr, 2) }}</strong></span>
          <span class="me-3">Total Withdrawals: <strong class="text-danger">{{ number_format($totalCr, 2) }}</strong></span>
          <span>Closing Balance: <strong class="text-primary">{{ $lastRow['balance'] ?? '0.00' }}</strong></span>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-bordered table-sm table-striped" id="bbTable">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Particulars</th>
              <th class="text-end">Debit (Deposit)</th>
              <th class="text-end">Credit (Withdrawal)</th>
              <th class="text-end">Balance</th>
            </tr>
          </thead>
          <tbody>
            @forelse($reportData as $row)
              <tr>
                <td>{{ \Carbon\Carbon::parse($row['date'])->format('d-M-Y') }}</td>
                <td><small>{{ $row['bank'] }}</small></td>
                <td class="text-end text-success">
                  {{ $row['debit'] !== '0.00' ? $row['debit'] : '—' }}
                </td>
                <td class="text-end text-danger">
                  {{ $row['credit'] !== '0.00' ? $row['credit'] : '—' }}
                </td>
                <td class="text-end fw-bold {{ ($row['balance_dr'] ?? true) ? 'text-primary' : 'text-danger' }}">
                  {{ $row['balance'] }}
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-center text-muted">No bank transactions found.</td></tr>
            @endforelse
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr>
              <td colspan="2" class="text-end">Totals</td>
              <td class="text-end text-success">{{ number_format($totalDr, 2) }}</td>
              <td class="text-end text-danger">{{ number_format($totalCr, 2) }}</td>
              <td class="text-end">{{ $lastRow['balance'] ?? '0.00' }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    @endif

    {{-- ── 10. JOURNAL / DAY BOOK ──────────────────────────────── --}}
    @if($report === 'journal_book')
      @php
        $totalAmt = collect($reportData)->sum(fn($r) => (float) str_replace(',', '', $r['amount']));
      @endphp
      <div class="row mb-2">
        <div class="col text-end">
          Total Entries: <strong>{{ count($reportData) }}</strong>
          &nbsp;|&nbsp;
          Total Amount: <strong>{{ number_format($totalAmt, 2) }}</strong>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-bordered table-sm table-striped" id="jbTable">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Voucher / Ref</th>
              <th>Debit Account</th>
              <th>Credit Account</th>
              <th class="text-end">Amount</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            @forelse($reportData as $row)
              <tr>
                <td>{{ \Carbon\Carbon::parse($row['date'])->format('d-M-Y') }}</td>
                <td><small>{{ $row['voucher'] }}</small></td>
                <td class="text-success">{{ $row['dr_account'] }}</td>
                <td class="text-danger">{{ $row['cr_account'] }}</td>
                <td class="text-end fw-bold">{{ $row['amount'] }}</td>
                <td><small class="text-muted">{{ $row['remarks'] ?? '' }}</small></td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-muted">No journal entries found.</td></tr>
            @endforelse
          </tbody>
          <tfoot class="table-light fw-bold">
            <tr>
              <td colspan="4" class="text-end">Total</td>
              <td class="text-end">{{ number_format($totalAmt, 2) }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    @endif

    {{-- ── 11. EXPENSE ANALYSIS ────────────────────────────────── --}}
    @if($report === 'expense_analysis')
      @php
        $totalExp = collect($reportData)->sum(fn($r) => (float) str_replace(',', '', $r['amount']));
      @endphp
      <div class="row mb-3">
        <div class="col text-end">
          Total Expenses: <strong class="text-danger fs-5">PKR {{ number_format($totalExp, 2) }}</strong>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-bordered table-sm table-striped" id="eaTable">
          <thead class="table-light">
            <tr>
              <th>Expense Head</th>
              <th>Type</th>
              <th class="text-end">Amount (PKR)</th>
              <th style="width:25%">% of Total</th>
            </tr>
          </thead>
          <tbody>
            @forelse($reportData as $row)
              @php $pct = $totalExp > 0 ? round((float) str_replace(',', '', $row['amount']) / $totalExp * 100, 1) : 0; @endphp
              <tr>
                <td>{{ $row['expense_head'] }}</td>
                <td>
                  <span class="badge {{ $row['account_type'] === 'cogs' ? 'bg-warning text-dark' : 'bg-secondary' }}">
                    {{ $row['account_type'] === 'cogs' ? 'COGS' : 'Expense' }}
                  </span>
                </td>
                <td class="text-end fw-bold">{{ $row['amount'] }}</td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height:8px;">
                      <div class="progress-bar bg-danger" style="width:{{ $pct }}%"></div>
                    </div>
                    <small class="text-muted">{{ $pct }}%</small>
                  </div>
                </td>
              </tr>
            @empty
              <tr><td colspan="4" class="text-center text-muted">No expense data found.</td></tr>
            @endforelse
          </tbody>
          @if(count($reportData))
            <tfoot class="table-light fw-bold">
              <tr>
                <td colspan="2" class="text-end">Grand Total</td>
                <td class="text-end text-danger">{{ number_format($totalExp, 2) }}</td>
                <td></td>
              </tr>
            </tfoot>
          @endif
        </table>
      </div>
    @endif

    {{-- ── 12. CASH FLOW ───────────────────────────────────────── --}}
    @if($report === 'cash_flow')
      <div class="table-responsive">
        <table class="table table-bordered table-sm" id="cfTable">
          <thead class="table-light">
            <tr>
              <th>Activity</th>
              <th class="text-end">Inflows (PKR)</th>
              <th class="text-end">Outflows (PKR)</th>
              <th class="text-end">Net Flow (PKR)</th>
            </tr>
          </thead>
          <tbody>
            @foreach($reportData as $row)
              @php
                $net     = (float) str_replace(',', '', $row['net flow']);
                $isTotal = $row['activity'] === 'NET CASH FLOW';
                $rowClass = $isTotal
                  ? ($net >= 0 ? 'table-success fw-bold' : 'table-danger fw-bold')
                  : '';
              @endphp
              <tr class="{{ $rowClass }}">
                <td>{{ $row['activity'] }}</td>
                <td class="text-end text-success">
                  {{ $row['inflows'] !== '0.00' ? $row['inflows'] : '—' }}
                </td>
                <td class="text-end text-danger">
                  {{ $row['outflows'] !== '0.00' ? $row['outflows'] : '—' }}
                </td>
                <td class="text-end fw-bold {{ $net >= 0 ? 'text-success' : 'text-danger' }}">
                  {{ $net < 0 ? '(' . number_format(abs($net), 2) . ')' : $row['net flow'] }}
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif

  </div>
</div>

<script>
  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });

    // Initialize DataTables only for large result sets
    const tables = ['#glTable','#tbTable','#plrTable','#jbTable','#cbTable','#bbTable','#recTable','#payTable','#eaTable'];
    tables.forEach(id => {
      if ($(id).length && $(id + ' tbody tr').length > 20) {
        $(id).DataTable({ pageLength: 100, order: [] });
      }
    });
  });
</script>
@endsection