@extends('layouts.app')
@section('title', 'Product Barcoding')

@section('content')

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="card-title">Product Barcoding</h4>
        <input type="text" id="searchBox" class="form-control w-25" placeholder="Search name / SKU / barcode...">
    </div>

    <div class="card-body">
        {{-- Validation Errors --}}
        @if ($errors->any())
            <div class="alert alert-danger">
                <strong>There were some problems with your input:</strong>
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Session Error --}}
        @if (session('error'))
            <div class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif

        <form action="{{ route('products.generateBarcodes') }}" method="POST" id="barcodeForm">
            @csrf
            <input type="hidden" name="items_json" id="itemsJson">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <strong id="selCount">0</strong> ticked
                    <button type="button" class="btn btn-sm btn-link" id="clearSel">Clear</button>
                    <small class="text-muted ms-2">Ticks and quantities are kept while you search or change pages.</small>
                </div>
                <div class="text-end">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-upc-scan"></i> Generate Barcodes (Browser Print)
                </button>
                <button type="submit" formaction="{{ route('products.generateZplLabels') }}" class="btn btn-outline-primary">
                    <i class="bi bi-printer"></i> Print on Zebra (ZPL)
                </button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle" id="productsTable">
                    <thead class="table-light">
                        <tr>
                            <th width="5%">Select</th>
                            <th>Product</th>
                            <th>Variation (SKU)</th>
                            <th>Barcode</th>
                            <th>Selling Price</th>
                            <th>Quantity</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </form>
    </div>
</div>

{{-- Server-side table: one page of variations at a time --}}
<script>
$(function () {
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // id → quantity, for every ticked row (kept across pages / searches)
    const picked = new Map();
    // quantities typed on rows that are not ticked (so they are still there when ticked later)
    const qtyDraft = new Map();
    const qtyOf = id => picked.has(id) ? picked.get(id) : (qtyDraft.get(id) || 1);

    const table = $('#productsTable').DataTable({
        serverSide: true,
        processing: true,
        ajax: @json(route('products.barcode.data')),
        order: [],
        pageLength: 50,
        lengthMenu: [25, 50, 100, 200],
        dom: 'lrtip',                        // our own search box is above
        columns: [
            { data: null, orderable: false, render: (d, t, r) => `<input type="checkbox" class="pick" value="${r.id}">` },
            { data: 'product', render: d => esc(d) },
            { data: 'sku',     render: d => esc(d) },
            { data: 'barcode', render: d => esc(d) },
            { data: 'price',   orderable: false, className: 'text-end' },
            { data: null, orderable: false, render: (d, t, r) =>
                `<input type="number" min="1" value="${qtyOf(String(r.id))}" class="form-control form-control-sm qty" data-id="${r.id}">` },
        ],
        drawCallback: function () {
            $('#productsTable input.pick').each(function () { this.checked = picked.has(this.value); });
        }
    });

    function count() { $('#selCount').text(picked.size); }

    $('#productsTable').on('change', 'input.pick', function () {
        const id = this.value;
        if (this.checked) picked.set(id, Math.max(1, parseInt($(this).closest('tr').find('input.qty').val()) || 1));
        else picked.delete(id);
        count();
    });

    $('#productsTable').on('input change', 'input.qty', function () {
        const id = String($(this).data('id'));
        const q  = Math.max(1, parseInt(this.value) || 1);
        if (picked.has(id)) picked.set(id, q); else qtyDraft.set(id, q);
    });

    $('#clearSel').on('click', function () { picked.clear(); $('#productsTable input.pick').prop('checked', false); count(); });

    // search box → server-side search (debounced)
    let timer;
    $('#searchBox').on('input', function () {   // typing, pasting and autofill all count
        clearTimeout(timer);
        timer = setTimeout(() => table.search(this.value).draw(), 400);
    });

    // submit the ticked rows as ONE field ({"id": qty}); works for both print buttons
    $('#barcodeForm').on('submit', function (e) {
        if (!picked.size) { e.preventDefault(); alert('Tick at least one item first.'); return; }
        $('#itemsJson').val(JSON.stringify(Object.fromEntries(picked)));
    });
});
</script>

@endsection