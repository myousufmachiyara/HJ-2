@extends('layouts.app')

@section('title', 'Vouchers')

@section('content')
<div class="row">
  <div class="col">

    {{-- Flash Messages --}}
    @if(session('success'))
      <div class="alert alert-success alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('success') }}
      </div>
    @endif
    @if(session('error'))
      <div class="alert alert-danger alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('error') }}
      </div>
    @endif

    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Vouchers</h2>
        <button type="button" class="btn btn-primary modal-with-form" href="#addModal" id="addVoucherBtn">
          <i class="fas fa-plus"></i> Add New
        </button>
      </header>

      <div class="card-body">

        {{-- ── OUTER TABS ─────────────────────────────────────── --}}
        <ul class="nav nav-tabs nav-tabs-primary mb-3" id="voucherTabs">
          <li class="nav-item">
            <a class="nav-link active" href="#tab-journal" data-bs-toggle="tab" data-type="journal">
              <i class="fas fa-book me-1"></i> Journal
              @if($journal->isNotEmpty())
                <span class="badge bg-secondary">{{ $journal->count() }}</span>
              @endif
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#tab-payment" data-bs-toggle="tab" data-type="payment">
              <i class="fas fa-arrow-up me-1"></i> Payment
              @if($payment->isNotEmpty())
                <span class="badge bg-secondary">{{ $payment->count() }}</span>
              @endif
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#tab-receipt" data-bs-toggle="tab" data-type="receipt">
              <i class="fas fa-arrow-down me-1"></i> Receipt
              @if($receipt->isNotEmpty())
                <span class="badge bg-secondary">{{ $receipt->count() }}</span>
              @endif
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="#tab-system" data-bs-toggle="tab" data-type="system">
              <i class="fas fa-cogs me-1"></i> System Entries
              @if($system->isNotEmpty())
                <span class="badge bg-primary">{{ $system->count() }}</span>
              @endif
            </a>
          </li>
        </ul>

        {{-- ── OUTER TAB CONTENT ──────────────────────────────── --}}
        <div class="tab-content">

          <div class="tab-pane active" id="tab-journal">
            @include('vouchers._table', ['vouchers' => $journal, 'type' => 'journal'])
          </div>

          <div class="tab-pane" id="tab-payment">
            @include('vouchers._table', ['vouchers' => $payment, 'type' => 'payment'])
          </div>

          <div class="tab-pane" id="tab-receipt">
            @include('vouchers._table', ['vouchers' => $receipt, 'type' => 'receipt'])
          </div>

          <div class="tab-pane" id="tab-system">
            @include('vouchers._system_table', ['vouchers' => $system])
          </div>

        </div>
      </div>
    </section>

    {{-- ── ADD MODAL ─────────────────────────────────────────── --}}
    <div id="addModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" id="addForm" enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
          @csrf
          <input type="hidden" name="voucher_type" id="add_voucher_type" value="journal">

          <header class="card-header">
            <h2 class="card-title">Add <span id="add_modal_title">Journal</span> Voucher</h2>
          </header>

          <div class="card-body">
            <div class="row">
              <div class="col-lg-6 mb-2">
                <label>Date</label>
                <input type="date" class="form-control" name="date" value="{{ date('Y-m-d') }}" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Account Debit <span class="text-danger">*</span></label>
                <select data-plugin-selecttwo class="form-control select2-js" name="ac_dr_sid" required>
                  <option value="" disabled selected>Select Account</option>
                  @foreach($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Account Credit <span class="text-danger">*</span></label>
                <select data-plugin-selecttwo class="form-control select2-js" name="ac_cr_sid" required>
                  <option value="" disabled selected>Select Account</option>
                  @foreach($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Amount <span class="text-danger">*</span></label>
                <input type="number" class="form-control" name="amount" step="any" value="0" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Attachments</label>
                <input type="file" class="form-control" name="att[]" multiple accept=".zip,application/pdf,image/png,image/jpeg">
              </div>
              <div class="col-lg-12 mb-2">
                <label>Remarks</label>
                <textarea rows="3" class="form-control" name="remarks"></textarea>
              </div>
            </div>
          </div>

          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">
              Add <span id="add_btn_label">Journal</span> Voucher
            </button>
            <button class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>

    {{-- ── UPDATE MODAL ──────────────────────────────────────── --}}
    <div id="updateModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" id="updateForm" enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
          @csrf
          @method('PUT')

          <header class="card-header">
            <h2 class="card-title">Update Voucher</h2>
          </header>

          <div class="card-body">
            <div class="row">
              <div class="col-lg-6 mb-2">
                <label>Voucher #</label>
                <input type="text" class="form-control" id="update_id" disabled>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Date</label>
                <input type="date" class="form-control" name="date" id="update_date" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Account Debit <span class="text-danger">*</span></label>
                <select data-plugin-selecttwo class="form-control select2-js" name="ac_dr_sid" id="update_ac_dr_sid" required>
                  <option value="" disabled>Select Account</option>
                  @foreach($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Account Credit <span class="text-danger">*</span></label>
                <select data-plugin-selecttwo class="form-control select2-js" name="ac_cr_sid" id="update_ac_cr_sid" required>
                  <option value="" disabled>Select Account</option>
                  @foreach($accounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                  @endforeach
                </select>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Amount <span class="text-danger">*</span></label>
                <input type="number" class="form-control" name="amount" id="update_amount" step="any" required>
              </div>
              <div class="col-lg-6 mb-2">
                <label>Attachments</label>
                <input type="file" class="form-control" name="att[]" multiple accept=".zip,application/pdf,image/png,image/jpeg">
              </div>
              <div class="col-lg-12 mb-2">
                <label>Remarks</label>
                <textarea rows="3" class="form-control" name="remarks" id="update_remarks"></textarea>
              </div>
            </div>
          </div>

          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Update Voucher</button>
            <button class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>

    {{-- ── DELETE MODAL ──────────────────────────────────────── --}}
    <div id="deleteModal" class="modal-block modal-block-warning mfp-hide">
      <section class="card">
        <form method="POST" id="deleteForm">
          @csrf
          @method('DELETE')
          <header class="card-header">
            <h2 class="card-title">Delete Voucher</h2>
          </header>
          <div class="card-body">
            <p>Are you sure you want to delete this voucher?</p>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-danger">Delete</button>
            <button class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>

  </div>
</div>

<script>
  let activeType = 'journal';

  document.querySelectorAll('#voucherTabs .nav-link').forEach(function(tab) {
    tab.addEventListener('click', function() {
      activeType = this.getAttribute('data-type');
      const addBtn = document.getElementById('addVoucherBtn');

      if (activeType === 'system') {
        addBtn.style.display = 'none';
      } else {
        addBtn.style.display = 'inline-block';
        updateAddModal(activeType);
      }
    });
  });

  function updateAddModal(type) {
    const label = type.charAt(0).toUpperCase() + type.slice(1);
    document.getElementById('add_voucher_type').value      = type;
    document.getElementById('add_modal_title').textContent = label;
    document.getElementById('add_btn_label').textContent   = label;
    document.getElementById('addForm').action              = `/vouchers/${type}`;
  }

  updateAddModal('journal');

  function editVoucher(id, type) {
    document.getElementById('updateForm').action = `/vouchers/${type}/${id}`;
    fetch(`/vouchers/${type}/${id}`)
      .then(res => res.json())
      .then(data => {
        document.getElementById('update_id').value       = id;
        document.getElementById('update_date').value     = data.date.substring(0, 10);        
        document.getElementById('update_amount').value   = data.amount;
        document.getElementById('update_remarks').value  = data.remarks ?? '';
        $('#update_ac_dr_sid').val(data.ac_dr_sid).trigger('change');
        $('#update_ac_cr_sid').val(data.ac_cr_sid).trigger('change');
      });
  }

  function setDeleteId(id, type) {
    document.getElementById('deleteForm').action = `/vouchers/${type}/${id}`;
  }
</script>
@endsection