<div class="table-responsive">
  <table class="table table-bordered table-striped mb-0 voucher-datatable">
    <thead>
      <tr>
        <th>Vch#</th>
        <th>Date</th>
        <th>Debit Account</th>
        <th>Credit Account</th>
        <th>Remarks</th>
        <th>Amount</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      @forelse($vouchers as $row)
        <tr>
          <td>{{ $row->id }}</td>
          <td>{{ \Carbon\Carbon::parse($row->date)->format('d-m-Y') }}</td>
          <td>{{ $row->debitAccount->name  ?? 'N/A' }}</td>
          <td>{{ $row->creditAccount->name ?? 'N/A' }}</td>
          <td>{{ $row->remarks }}</td>
          <td><strong>{{ number_format($row->amount, 0, '.', ',') }}</strong></td>
          <td class="actions">
            <a class="text-success"
               href="{{ route('vouchers.print', ['type' => $type, 'id' => $row->id]) }}"
               title="Print">
              <i class="fas fa-print"></i>
            </a>
            @if(!$row->source_type)
              {{-- Only allow editing manually created vouchers, not auto-generated ones --}}
              <a class="text-primary modal-with-form"
                 href="#updateModal"
                 onclick="editVoucher({{ $row->id }}, '{{ $type }}')"
                 title="Edit">
                <i class="fas fa-edit"></i>
              </a>
              <a class="btn btn-link p-0 m-0 text-danger"
                 href="#deleteModal"
                 onclick="setDeleteId({{ $row->id }}, '{{ $type }}')"
                 title="Delete">
                <i class="fas fa-trash-alt"></i>
              </a>
            @else
              <span class="badge bg-secondary" title="Auto-generated from {{ class_basename($row->source_type) }}">
                Auto
              </span>
            @endif
          </td>
        </tr>
      @empty
        <tr>
          <td colspan="7" class="text-center text-muted">No {{ ucfirst($type) }} vouchers found.</td>
        </tr>
      @endforelse
    </tbody>
    @if($vouchers->isNotEmpty())
      <tfoot>
        <tr class="table-light fw-bold">
          <td colspan="5" class="text-end">Total</td>
          <td>{{ number_format($vouchers->sum('amount'), 0, '.', ',') }}</td>
          <td></td>
        </tr>
      </tfoot>
    @endif
  </table>
</div>