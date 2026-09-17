@extends('layouts.app')
@section('title', 'Print Barcodes — Zebra (ZPL)')

@section('content')

<div class="card shadow-sm">
    <div class="card-header">
        <h4 class="card-title mb-0">Print Barcodes — Zebra (ZPL)</h4>
    </div>

    <div class="card-body">

        <div class="alert alert-info">
            This sends native ZPL straight to the Zebra printer via
            <a href="https://qz.io/download/" target="_blank" rel="noopener">QZ Tray</a>,
            bypassing the browser's print dialog entirely — no page-size
            negotiation, no driver rotation setting, no image upscaling.
            <strong>QZ Tray must be installed and running</strong> (look for
            its icon in the system tray) on this computer for this to work.
            Since this install isn't using a signed certificate yet, QZ Tray
            will show a one-time "allow this site to print?" popup the first
            time — click <em>Allow</em> (and "remember this decision" if
            offered).
        </div>

        <div id="qz-status" class="alert alert-secondary">Connecting to QZ Tray…</div>

        <div class="row g-2 align-items-end mb-3">
            <div class="col-auto">
                <label for="qz-printer" class="form-label mb-0">Printer</label>
                <select id="qz-printer" class="form-select" style="min-width: 260px;">
                    <option>—</option>
                </select>
            </div>
            <div class="col-auto">
                <button id="qz-print-btn" class="btn btn-primary" disabled>
                    <i class="bi bi-printer"></i> Print {{ count($zplBlocks) }} label(s)
                </button>
            </div>
            <div class="col-auto">
                <button id="qz-reconnect-btn" class="btn btn-outline-secondary">
                    Reconnect
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Brand</th>
                        <th>Product</th>
                        <th>Variation</th>
                        <th>Barcode</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($barcodes as $barcode)
                        <tr>
                            <td>{{ $barcode['brand'] ?: '—' }}</td>
                            <td>{{ $barcode['product'] }}</td>
                            <td>{{ $barcode['variation'] ?: '—' }}</td>
                            <td><code>{{ $barcode['barcodeText'] }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-muted small mb-0">
            Label geometry is fixed server-side (38mm x 26mm at 203dpi) —
            see <code>ProductController::labelZplDots()</code> if the
            physical label size needs correcting.
        </p>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const zplBlocks     = @json($zplBlocks);
    const statusEl      = document.getElementById('qz-status');
    const printerSelect = document.getElementById('qz-printer');
    const printBtn      = document.getElementById('qz-print-btn');
    const reconnectBtn  = document.getElementById('qz-reconnect-btn');

    function setStatus(msg, cls) {
        statusEl.textContent = msg;
        statusEl.className = 'alert alert-' + (cls || 'secondary');
    }

    function connectAndListPrinters() {
        setStatus('Connecting to QZ Tray…', 'secondary');
        printBtn.disabled = true;

        const connected = qz.websocket.isActive()
            ? Promise.resolve()
            : qz.websocket.connect();

        connected
            .then(() => qz.printers.find())
            .then(printers => {
                printerSelect.innerHTML = '';
                printers.forEach(name => {
                    const opt = document.createElement('option');
                    opt.value = name;
                    opt.textContent = name;
                    printerSelect.appendChild(opt);
                });

                // Prefer a printer whose name mentions Zebra/GC420, but the
                // dropdown lets the operator override if this machine has
                // more than one printer installed.
                const preferred = printers.find(n => /zebra|gc4?20/i.test(n));
                if (preferred) printerSelect.value = preferred;

                printBtn.disabled = printers.length === 0;
                setStatus(
                    printers.length
                        ? `Connected — found ${printers.length} printer(s).`
                        : 'Connected to QZ Tray, but no printers were found on this computer.',
                    printers.length ? 'success' : 'warning'
                );
            })
            .catch(err => {
                console.error(err);
                printBtn.disabled = true;
                setStatus('Could not reach QZ Tray. Make sure it is installed and running (check the system tray icon), then click Reconnect.', 'danger');
            });
    }

    printBtn.addEventListener('click', function () {
        const printerName = printerSelect.value;
        if (!printerName || printerName === '—') return;

        printBtn.disabled = true;
        setStatus(`Sending ${zplBlocks.length} label(s) to ${printerName}…`, 'secondary');

        const config = qz.configs.create(printerName, { encoding: 'UTF-8' });

        qz.print(config, zplBlocks)
            .then(() => {
                setStatus(`Sent ${zplBlocks.length} label(s) to ${printerName}.`, 'success');
                printBtn.disabled = false;
            })
            .catch(err => {
                console.error(err);
                setStatus('Print failed: ' + (err && err.message ? err.message : err), 'danger');
                printBtn.disabled = false;
            });
    });

    reconnectBtn.addEventListener('click', connectAndListPrinters);

    connectAndListPrinters();
});
</script>

@endsection