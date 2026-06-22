<div class="row g-3 mb-3">
  <div class="col-md-3">
    <label>From Date</label>
    <input type="date" name="from_date" class="form-control" value="{{ request('from_date', $from) }}">
  </div>
  <div class="col-md-3">
    <label>To Date</label>
    <input type="date" name="to_date" class="form-control" value="{{ request('to_date', $to) }}">
  </div>
  <div class="col-md-2 d-flex align-items-end">
    <button type="submit" class="btn btn-primary w-100">
      <i class="fas fa-filter me-1"></i> Filter
    </button>
  </div>
  <div class="col-md-1 d-flex align-items-end">
    <a href="{{ request()->url() }}?tab={{ request('tab', 'RMI') }}" class="btn btn-secondary w-100">
      Reset
    </a>
  </div>
</div>