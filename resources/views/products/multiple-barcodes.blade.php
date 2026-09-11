<!DOCTYPE html>
<html>
<head>
    <title>Print Barcodes</title>
    <style>
        /* ────────────────────────────────────────────────────────────
           THERMAL LABEL SIZE — change these two values to match your
           label stock, then reload/print. Common sizes: 50x25, 50x30,
           40x30, 58x40 (all in mm). Nothing else needs to change.
           ──────────────────────────────────────────────────────────── */
        @page {
            size: 50mm 30mm;   /* ← label width x height */
            margin: 0;         /* thermal printers have no page margins */
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
        }

        /* Each label is its own printed page — exact match to @page size
           above. This is what makes ONE sticker come out per label, instead
           of the browser trying to fit a grid onto A4. */
        .barcode-label {
            width: 50mm;
            height: 30mm;
            padding: 1mm 2mm;
            margin: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            overflow: hidden;
            page-break-after: always;   /* one label = one physical print */
            break-after: page;          /* modern browsers */
        }

        .barcode-label:last-child {
            page-break-after: auto;
            break-after: auto;
        }

        .barcode-label strong,
        .barcode-label small {
            display: block;
            width: 100%;
            line-height: 1.05;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .barcode-label strong {
            font-size: 8px;
        }

        .barcode-label small {
            font-size: 6.5px;
        }

        .barcode-label .brand {
            font-size: 6px;
            text-transform: uppercase;
            color: #555;
        }

        .barcode-label .compare-price {
            text-decoration: line-through;
            color: #888;
            font-size: 6.5px;
        }

        .barcode-label .price {
            font-size: 9px;
            font-weight: bold;
        }

        .barcode-label img {
            width: 44mm;      /* leaves ~2mm margin either side of a 50mm label */
            max-width: 100%;
            height: 10mm;     /* slightly shorter than the earlier version to
                                  leave room for brand + compare-price lines */
            object-fit: contain;
            margin: 0.5mm 0;
        }

        /* On-screen only: lets you preview labels stacked before printing,
           since @page forces one-per-page only when actually printing. */
        .no-print {
            text-align: center;
            margin: 16px 0;
        }

        @media screen {
            body {
                background: #ddd;
            }
            .barcode-label {
                background: #fff;
                border: 1px solid #999;
                margin: 6px auto;
                box-shadow: 0 1px 3px rgba(0,0,0,.2);
            }
        }

        @media print {
            .no-print {
                display: none;
            }
            body {
                background: #fff;
            }
        }
    </style>
</head>
<body>

@foreach($barcodes as $barcode)
    <div class="barcode-label">
        @if(!empty($barcode['brand']))
            <small class="brand">{{ $barcode['brand'] }}</small>
        @endif
        <strong>{{ $barcode['product'] }}</strong>
        @if(!empty($barcode['variation']))
            <small>{{ $barcode['variation'] }}</small>
        @endif
        <img src="data:image/png;base64,{{ $barcode['barcodeImage'] }}" alt="barcode">
        <small>{{ $barcode['barcodeText'] }}</small>
        @if(!empty($barcode['comparePrice']))
            <small class="compare-price">Rs. {{ $barcode['comparePrice'] }}</small>
        @endif
        <span class="price">Rs. {{ $barcode['price'] }}</span>
    </div>
@endforeach

<div class="no-print">
    <button onclick="window.print()">Print Labels</button>
</div>

</body>
</html>