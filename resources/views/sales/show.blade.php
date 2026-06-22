@extends('layouts.app')
@section('title', 'Sale Invoice | ' . $invoice->invoice_no)

@section('content')
<div class="row">
  <div class="col">

    @if(session('success'))
      <div class="alert alert-success">{{ session('success') }}</div>
    @elseif(session('error'))
      <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    {{-- Invoice Summary Card --}}
    <section class="card mb-3">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0">
          Sale Invoice <strong class="text-primary">{{ $invoice->invoice_no }}</strong>
        </h2>
        <div class="d-flex gap-2">
          <a href="{{ route('sale_invoices.edit', $invoice->id) }}" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-edit me-1"></i> Edit
          </a>
          <a href="{{ route('sale_invoices.print', $invoice->id) }}" target="_blank" class="btn btn-sm btn-outline-success">
            <i class="fas fa-print me-1"></i> Print
          </a>
          <a href="{{ route('sale_invoices.index') }}" class="btn btn-sm btn-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back
          </a>
        </div>
      </header>

      <div class="card-body">
        {{-- Info Row --}}
        <div class="row mb-3">
          <div class="col-md-3">
            <small class="text-muted d-block">Customer</small>
            <strong>{{ $invoice->account->name ?? 'Walk-in' }}</strong>
          </div>
          <div class="col-md-2">
            <small class="text-muted d-block">Date</small>
            <strong>{{ \Carbon\Carbon::parse($invoice->date)->format('d-M-Y') }}</strong>
          </div>
          <div class="col-md-2">
            <small class="text-muted d-block">Type</small>
            <span class="badge {{ $invoice->type === 'credit' ? 'bg-warning text-dark' : 'bg-success' }}">
              {{ ucfirst($invoice->type) }}
            </span>
          </div>
          <div class="col-md-2">
            <small class="text-muted d-block">Status</small>
            @php
              $badge = match($invoice->payment_status) {
                'paid'    => 'bg-success',
                'partial' => 'bg-warning text-dark',
                default   => 'bg-danger',
              };
            @endphp
            <span class="badge {{ $badge }}">{{ ucfirst($invoice->payment_status) }}</span>
          </div>
          <div class="col-md-3 text-end">
            <small class="text-muted d-block">Net Amount</small>
            <h4 class="mb-0 text-primary">PKR {{ number_format($invoice->net_amount, 2) }}</h4>
          </div>
        </div>

        {{-- Items Table --}}
        <div class="table-responsive">
          <table class="table table-bordered table-sm table-hover">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Item</th>
                <th>Variation</th>
                <th class="text-end">Qty</th>
                <th class="text-end">Price</th>
                <th class="text-end">Disc%</th>
                <th class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>
              @foreach($invoice->items as $i => $item)
                <tr>
                  <td>{{ $i + 1 }}</td>
                  <td>{{ $item->product->name ?? '-' }}</td>
                  <td>{{ $item->variation->sku ?? '-' }}</td>
                  <td class="text-end">
                    {{ number_format($item->quantity, 2) }}
                    {{ $item->measurementUnit->shortcode ?? '' }}
                  </td>
                  <td class="text-end">{{ number_format($item->sale_price, 2) }}</td>
                  <td class="text-end">{{ $item->discount ?? 0 }}%</td>
                  <td class="text-end">{{ number_format($item->getLineTotal(), 2) }}</td>
                </tr>
              @endforeach
            </tbody>
            <tfoot class="table-light">
              <tr>
                <td colspan="6" class="text-end">Sub Total</td>
                <td class="text-end">{{ number_format($invoice->sub_total, 2) }}</td>
              </tr>
              @if($invoice->discount > 0)
                <tr>
                  <td colspan="6" class="text-end text-muted">Bill Discount</td>
                  <td class="text-end text-muted">({{ number_format($invoice->discount, 2) }})</td>
                </tr>
              @endif
              @if($invoice->convance_charges > 0)
                <tr>
                  <td colspan="6" class="text-end text-muted">Conveyance</td>
                  <td class="text-end text-muted">{{ number_format($invoice->convance_charges, 2) }}</td>
                </tr>
              @endif
              <tr class="fw-bold">
                <td colspan="6" class="text-end">Net Total</td>
                <td class="text-end text-primary">PKR {{ number_format($invoice->net_amount, 2) }}</td>
              </tr>
              <tr>
                <td colspan="6" class="text-end text-success">Paid</td>
                <td class="text-end text-success">{{ number_format($invoice->paid_amount, 2) }}</td>
              </tr>
              <tr class="fw-bold">
                <td colspan="6" class="text-end {{ $invoice->balance > 0 ? 'text-danger' : 'text-success' }}">
                  Balance
                </td>
                <td class="text-end {{ $invoice->balance > 0 ? 'text-danger' : 'text-success' }}">
                  {{ number_format($invoice->balance, 2) }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </section>

    {{-- Payment Management Card --}}
    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Payment Management</h5>
        @if($invoice->balance > 0)
          <button class="btn btn-success btn-sm" data-bs-toggle="collapse"
                  data-bs-target="#addPaymentForm">
            <i class="fas fa-plus me-1"></i> Add Payment
          </button>
        @else
          <span class="badge bg-success">Fully Paid</span>
        @endif
      </header>

      {{-- Add Payment Form --}}
      @if($invoice->balance > 0)
        <div class="collapse" id="addPaymentForm">
          <div class="card-body border-bottom bg-light">
            <form action="{{ route('sale_invoices.payments.store', $invoice->id) }}" method="POST">
              @csrf
              <div class="row g-3 align-items-end">
                <div class="col-md-3">
                  <label class="form-label fw-bold">Receive Into <span class="text-danger">*</span></label>
                  <select data-plugin-selecttwo class="form-control select2-js" name="account_id" required>
                    <option value="">Select Account</option>
                    @foreach($accounts as $acc)
                      <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                    @endforeach
                  </select>
                </div>
                <div class="col-md-2">
                  <label class="form-label fw-bold">Payment Date <span class="text-danger">*</span></label>
                  <input type="date" name="payment_date" class="form-control"
                         value="{{ date('Y-m-d') }}" required>
                </div>
                <div class="col-md-2">
                  <label class="form-label fw-bold">Amount <span class="text-danger">*</span></label>
                  <input type="number" name="amount" class="form-control"
                         value="{{ $invoice->balance }}" step="any"
                         max="{{ $invoice->balance }}" required>
                  <small class="text-muted">Balance: PKR {{ number_format($invoice->balance, 2) }}</small>
                </div>
                <div class="col-md-2">
                  <label class="form-label fw-bold">Reference</label>
                  <input type="text" name="reference" class="form-control" placeholder="Cheque/TRX no.">
                </div>
                <div class="col-md-2">
                  <label class="form-label fw-bold">Remarks</label>
                  <input type="text" name="remarks" class="form-control">
                </div>
                <div class="col-md-1">
                  <button type="submit" class="btn btn-success w-100">
                    <i class="fas fa-save"></i>
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      @endif

      {{-- Existing Payments --}}
      <div class="card-body">
        @if($invoice->payments->count())
          <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Date</th>
                  <th>Account</th>
                  <th>Reference</th>
                  <th>Remarks</th>
                  <th class="text-end">Amount</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                @foreach($invoice->payments as $i => $payment)
                  <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ \Carbon\Carbon::parse($payment->payment_date)->format('d-M-Y') }}</td>
                    <td>{{ $payment->account->name ?? '-' }}</td>
                    <td>{{ $payment->reference ?? '-' }}</td>
                    <td>{{ $payment->remarks ?? '-' }}</td>
                    <td class="text-end text-success fw-bold">
                      {{ number_format($payment->amount, 2) }}
                    </td>
                    <td>
                      {{-- Edit Payment --}}
                      <button class="btn btn-xs btn-outline-primary"
                              onclick="editPayment({{ $payment->id }},
                                '{{ $payment->account_id }}',
                                '{{ $payment->payment_date }}',
                                {{ $payment->amount }},
                                '{{ $payment->reference }}',
                                '{{ $payment->remarks }}')"
                              title="Edit">
                        <i class="fas fa-edit"></i>
                      </button>
                      {{-- Delete Payment --}}
                      <form action="{{ route('sale_invoices.payments.destroy', [$invoice->id, $payment->id]) }}"
                            method="POST" style="display:inline;"
                            onsubmit="return confirm('Delete this payment?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-xs btn-outline-danger" title="Delete">
                          <i class="fas fa-trash-alt"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                @endforeach
              </tbody>
              <tfoot class="table-light fw-bold">
                <tr>
                  <td colspan="5" class="text-end">Total Paid</td>
                  <td class="text-end text-success">
                    {{ number_format($invoice->paid_amount, 2) }}
                  </td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
        @else
          <p class="text-muted mb-0">No payments recorded yet.</p>
        @endif
      </div>
    </section>

  </div>
</div>

{{-- Edit Payment Modal --}}
<div class="modal fade" id="editPaymentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="editPaymentForm" method="POST">
        @csrf @method('PUT')
        <div class="modal-body">
          <div class="mb-3">
            <label class="fw-bold">Account <span class="text-danger">*</span></label>
            <select name="account_id" id="ep_account" data-plugin-selecttwo class="form-control select2-js" required>
              @foreach($accounts as $acc)
                <option value="{{ $acc->id }}">{{ $acc->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="mb-3">
            <label class="fw-bold">Payment Date <span class="text-danger">*</span></label>
            <input type="date" name="payment_date" id="ep_date" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="fw-bold">Amount <span class="text-danger">*</span></label>
            <input type="number" name="amount" id="ep_amount" class="form-control" step="any" required>
          </div>
          <div class="mb-3">
            <label class="fw-bold">Reference</label>
            <input type="text" name="reference" id="ep_reference" class="form-control">
          </div>
          <div class="mb-3">
            <label class="fw-bold">Remarks</label>
            <input type="text" name="remarks" id="ep_remarks" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function editPayment(paymentId, accountId, date, amount, reference, remarks) {
  const baseUrl = '{{ route("sale_invoices.payments.update", [$invoice->id, "__ID__"]) }}'
    .replace('__ID__', paymentId);

  document.getElementById('editPaymentForm').action = baseUrl;
  document.getElementById('ep_account').value   = accountId;
  document.getElementById('ep_date').value      = date;
  document.getElementById('ep_amount').value    = amount;
  document.getElementById('ep_reference').value = reference || '';
  document.getElementById('ep_remarks').value   = remarks   || '';

  new bootstrap.Modal(document.getElementById('editPaymentModal')).show();
}
</script>
@endsection