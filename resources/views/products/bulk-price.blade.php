@extends('layouts.app')
@section('title', 'Bulk Price Update')

@section('content')
<div class="card shadow-sm">
    <div class="card-header">
        <h4 class="card-title mb-0">Bulk Price Update</h4>
        <small class="text-muted">
            Filter products by location, change the
            {{ $actualIsCompareAt ? 'actual (before-discount)' : 'selling' }} price, then export to Excel.
            The discount (after-discount) price changes only if you edit it, or use "Keep same discount %".
        </small>
    </div>

    <div class="card-body">
        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        {{-- ───────────── Filters ───────────── --}}
        <form method="GET" action="{{ route('bulk-price.index') }}" class="row g-2 align-items-end mb-4">
            <div class="col-md-3">
                <label class="form-label">Location <span class="text-danger">*</span></label>
                <select name="location_id" class="form-control" required>
                    <option value="">Select location</option>
                    @foreach($locations as $group => $locs)
                        <optgroup label="{{ $group }}">
                            @foreach($locs as $loc)
                                <option value="{{ $loc->id }}" @selected(request('location_id') == $loc->id)>{{ $loc->name }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Category</label>
                <select name="category_id" class="form-control">
                    <option value="">All</option>
                    @foreach($categories as $c)
                        <option value="{{ $c->id }}" @selected(request('category_id') == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-md-1">
                <label class="form-label">Stock min</label>
                <input type="number" step="any" name="stock_min" value="{{ request('stock_min') }}" class="form-control" placeholder="&gt;0">
            </div>
            <div class="col-md-1">
                <label class="form-label">Stock max</label>
                <input type="number" step="any" name="stock_max" value="{{ request('stock_max') }}" class="form-control">
            </div>

            <div class="col-md-2">
                <label class="form-label">Sort by</label>
                <select name="sort" class="form-control">
                    <option value="">SKU</option>
                    <option value="best_seller" @selected(request('sort') == 'best_seller')>Best seller</option>
                    <option value="least_sold"  @selected(request('sort') == 'least_sold')>Least sold</option>
                </select>
            </div>

            <div class="col-md-1">
                <label class="form-label">Top N</label>
                <input type="number" min="1" name="limit" value="{{ request('limit') }}" class="form-control" placeholder="All">
            </div>

            <div class="col-md-2">
                <label class="form-label">Sold counted at</label>
                <select name="sold_scope" class="form-control">
                    <option value="">This location</option>
                    <option value="all" @selected(request('sold_scope') == 'all')>All locations</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Sales from</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">Sales to</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">Items</label>
                <select name="item_type" class="form-control">
                    <option value="fg">Finished goods</option>
                    <option value="all" @selected(request('item_type') == 'all')>Everything</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Attributes <small class="text-muted">(size, colour… — pick several)</small></label>
                <select name="attr_values[]" id="attrValues" class="form-control" multiple>
                    @foreach($attributes as $a)
                        <optgroup label="{{ $a->name }}">
                            @foreach($a->values as $val)
                                <option value="{{ $val->id }}" @selected(in_array($val->id, array_map('intval', (array) request('attr_values', []))))>{{ $val->value }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Price from</label>
                <input type="number" step="any" min="0" name="price_from" value="{{ request('price_from') }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">Price to</label>
                <input type="number" step="any" min="0" name="price_to" value="{{ request('price_to') }}" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">Range on</label>
                <select name="price_on" class="form-control">
                    <option value="actual">Price before discount</option>
                    <option value="discount" @selected(request('price_on') == 'discount')>Price after discount</option>
                </select>
            </div>

            <div class="col-md-2">
                <button class="btn btn-primary w-100"><i class="fa fa-filter"></i> Filter</button>
            </div>
        </form>

        {{-- ───────────── Results ───────────── --}}
        @if($rows->isNotEmpty())
        <form method="POST" action="{{ route('bulk-price.export') }}" id="exportForm">
            @csrf
            <input type="hidden" name="payload" id="payload">

            {{-- Apply one change to every ticked row --}}
            <div class="row g-2 align-items-end mb-3 p-2 border rounded bg-light">
                <div class="col-md-2">
                    <label class="form-label">Apply to</label>
                    <select id="bulkTarget" class="form-control">
                        <option value="actual">Price (before discount)</option>
                        <option value="discount">Discount price (after)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Change ticked rows</label>
                    <select id="bulkMode" class="form-control">
                        <option value="set">Set price to</option>
                        <option value="inc_pct">Increase by %</option>
                        <option value="dec_pct">Decrease by %</option>
                        <option value="inc_amt">Increase by amount</option>
                        <option value="dec_amt">Decrease by amount</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Value</label>
                    <input type="number" step="0.01" id="bulkValue" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Round to nearest</label>
                    <select id="bulkRound" class="form-control">
                        <option value="0">No rounding</option>
                        <option value="1">1</option>
                        <option value="10">10</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-secondary w-100" id="applyBulk">Apply to ticked</button>
                </div>
                <div class="col-md-3">
                    <button type="button" class="btn btn-outline-primary w-100" id="keepPct"
                            title="Sets each ticked item's discount price so it keeps the same discount % after the new price">Keep same discount %</button>
                </div>
                <div class="col-md-12 text-end mt-1">
                    <input type="text" id="searchBox" class="form-control d-inline-block w-25" placeholder="Search SKU / name...">
                    <span class="ms-2"><strong id="selCount">0</strong> ticked</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle" id="priceTable">
                    <thead class="table-light">
                        <tr>
                            <th width="4%"><input type="checkbox" id="checkAll"></th>
                            <th>SKU</th>
                            <th>Product</th>
                            <th>Category</th>
                            <th class="text-end">Stock</th>
                            <th class="text-end">Sold</th>
                            <th class="text-end">Current price</th>
                            <th class="text-end">Discount price</th>
                            <th width="12%">New price</th>
                            <th width="12%">New discount price</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $row)
                        <tr data-product="{{ $row->product_id }}" data-variation="{{ $row->variation_id }}"
                            data-current="{{ $row->actual }}" data-discount="{{ $row->discount }}">
                            <td><input type="checkbox" class="row-check"></td>
                            <td class="sku">{{ $row->sku }}</td>
                            <td class="pname">{{ $row->name }}</td>
                            <td>{{ $row->category }}</td>
                            <td class="text-end">{{ rtrim(rtrim(number_format($row->stock, 3, '.', ''), '0'), '.') }}</td>
                            <td class="text-end">{{ rtrim(rtrim(number_format($row->sold, 3, '.', ''), '0'), '.') }}</td>
                            <td class="text-end">{{ $row->actual !== null ? number_format($row->actual, 2) : '-' }}</td>
                            <td class="text-end">{{ $row->discount !== null ? number_format($row->discount, 2) : '-' }}</td>
                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm new-price"
                                       value="{{ $row->actual }}"></td>
                            <td><input type="number" step="0.01" min="0" class="form-control form-control-sm new-discount"
                                       value="{{ $row->discount }}" @disabled($row->discount === null)></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="text-end">
                <button type="submit" name="push" value="1" class="btn btn-info text-white">
                    <i class="fab fa-shopify"></i> Save prices &amp; Push to Shopify
                </button>
                <button type="submit" class="btn btn-success">
                    <i class="fa fa-file-excel"></i> Save prices &amp; Export Excel
                </button>
            </div>
        </form>
        @elseif(request('location_id'))
            <div class="alert alert-info mb-0">No products match these filters.</div>
        @endif
    </div>
</div>

<script>
window.addEventListener('load', function () {
    // multi-select with search for the attribute filter (plain multi-select if select2 is not loaded)
    if (window.jQuery && jQuery.fn.select2) jQuery('#attrValues').select2({ width: '100%', placeholder: 'All attributes' });
});
(function () {
    const rows     = () => Array.from(document.querySelectorAll('#priceTable tbody tr'));
    const visible  = tr => tr.style.display !== 'none';
    const countSel = () => {
        const el = document.getElementById('selCount');
        if (el) el.textContent = rows().filter(tr => tr.querySelector('.row-check').checked).length;
    };

    // tick all visible rows
    document.getElementById('checkAll')?.addEventListener('change', e => {
        rows().filter(visible).forEach(tr => tr.querySelector('.row-check').checked = e.target.checked);
        countSel();
    });
    document.addEventListener('change', e => { if (e.target.classList.contains('row-check')) countSel(); });

    // mark edited rows + tick them automatically
    document.addEventListener('input', e => {
        if (!e.target.classList.contains('new-price') && !e.target.classList.contains('new-discount')) return;
        const tr = e.target.closest('tr');
        const dis = tr.querySelector('.new-discount');
        const changed = parseFloat(tr.querySelector('.new-price').value) !== parseFloat(tr.dataset.current)
                     || (!dis.disabled && parseFloat(dis.value) !== parseFloat(tr.dataset.discount));
        tr.classList.toggle('table-warning', changed);
        if (tr.classList.contains('table-warning')) tr.querySelector('.row-check').checked = true;
        countSel();
    });

    // search box
    document.getElementById('searchBox')?.addEventListener('keyup', function () {
        const f = this.value.toLowerCase();
        rows().forEach(tr => {
            const text = (tr.querySelector('.sku').textContent + ' ' + tr.querySelector('.pname').textContent).toLowerCase();
            tr.style.display = text.includes(f) ? '' : 'none';
        });
    });

    // apply set / % / amount change to ticked rows
    document.getElementById('applyBulk')?.addEventListener('click', () => {
        const mode  = document.getElementById('bulkMode').value;
        const val   = parseFloat(document.getElementById('bulkValue').value);
        const round = parseFloat(document.getElementById('bulkRound').value) || 0;
        if (isNaN(val)) { alert('Enter a value first.'); return; }

        const target = document.getElementById('bulkTarget').value;
        rows().forEach(tr => {
            if (!tr.querySelector('.row-check').checked) return;
            if (target === 'discount' && tr.querySelector('.new-discount').disabled) return;   // item has no discount
            const cur = parseFloat(target === 'discount' ? tr.dataset.discount : tr.dataset.current) || 0;
            let np = val;
            if (mode === 'inc_pct') np = cur * (1 + val / 100);
            if (mode === 'dec_pct') np = cur * (1 - val / 100);
            if (mode === 'inc_amt') np = cur + val;
            if (mode === 'dec_amt') np = cur - val;
            if (round > 0) np = Math.round(np / round) * round;
            np = Math.max(0, np);

            const input = tr.querySelector(target === 'discount' ? '.new-discount' : '.new-price');
            input.value = np.toFixed(2);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    // keep each ticked item's discount % : new discount price = new price x (old discount / old price)
    document.getElementById('keepPct')?.addEventListener('click', () => {
        const round = parseFloat(document.getElementById('bulkRound').value) || 0;
        let n = 0;
        rows().forEach(tr => {
            if (!tr.querySelector('.row-check').checked) return;
            const dis = tr.querySelector('.new-discount'); if (dis.disabled) return;
            const oldP = parseFloat(tr.dataset.current), oldD = parseFloat(tr.dataset.discount);
            const newP = parseFloat(tr.querySelector('.new-price').value);
            if (!(oldP > 0) || isNaN(oldD) || isNaN(newP)) return;
            let nd = newP * (oldD / oldP);
            if (round > 0) nd = Math.round(nd / round) * round;
            dis.value = Math.min(Math.max(0, nd), newP).toFixed(2);
            dis.dispatchEvent(new Event('input', { bubbles: true })); n++;
        });
        if (!n) alert('Tick items that have a discount price and a new price first.');
    });

    // submit only the ticked rows, as one JSON field
    document.getElementById('exportForm')?.addEventListener('submit', e => {
        const items = rows()
            .filter(tr => tr.querySelector('.row-check').checked)
            .map(tr => ({
                product_id:   tr.dataset.product,
                variation_id: tr.dataset.variation || null,
                new_price:    tr.querySelector('.new-price').value,
                new_discount: tr.querySelector('.new-discount').disabled ? '' : tr.querySelector('.new-discount').value,
            }));

        if (!items.length) { e.preventDefault(); alert('Please tick at least one product.'); return; }
        const pushing = e.submitter && e.submitter.name === 'push';
        const ask = pushing
            ? 'Save the new prices for ' + items.length + ' product(s) AND send selling + compare-at prices to Shopify? This changes the live store.'
            : 'Save the new prices for ' + items.length + ' product(s) and export to Excel?';
        if (!confirm(ask)) { e.preventDefault(); return; }
        document.getElementById('payload').value = JSON.stringify(items);
    });
})();
</script>
@endsection
