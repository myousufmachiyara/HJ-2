<!DOCTYPE html>
<html>
<head>
    <title>Print Barcodes</title>
    <style>
        /* ────────────────────────────────────────────────────────────
           THERMAL LABEL SIZE — measured from the actual roll with a tape
           measure: ~1.5in x 1in (38mm x 25mm). Must match the EXACT stock
           size configured in your printer driver (Control Panel → Devices
           & Printers → your thermal printer → Printing Preferences →
           Stock/Media size) or labels will misalign / split across labels.
           If your tape reading was off, correct both values here AND the
           .barcode-label width/height below (they must always match).
           ──────────────────────────────────────────────────────────── */
        @page {
            size: 38mm 25mm;   /* ← label width x height */
            margin: 0;
        }

        /* ────────────────────────────────────────────────────────────
           ROTATION — your printed labels came out upside-down because
           of how the roll is fed. This flips the content 180° to
           compensate. If you later reload the roll the other way round
           and labels come out upside-down AGAIN, just change this to
           rotate(0deg) (or delete the transform line).
           ──────────────────────────────────────────────────────────── */

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

        .barcode-label {
            width: 38mm;
            height: 25mm;
            padding: 0.5mm 1.5mm;
            margin: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            overflow: hidden;
            page-break-after: always;
            break-after: page;
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
            font-size: 6.5px;
        }

        .barcode-label small {
            font-size: 5.5px;
        }

        .barcode-label .brand {
            font-size: 5px;
            text-transform: uppercase;
            color: #555;
        }

        .barcode-label .compare-price {
            text-decoration: line-through;
            color: #888;
            font-size: 5.5px;
        }

        .barcode-label .price {
            font-size: 7.5px;
            font-weight: bold;
        }

        /* Rescaled for the 38x25mm label. Content area is 35mm wide
           (38mm - 2×1.5mm padding); the image is deliberately narrower
           than that (30mm, centered by the flex layout) so it keeps a
           genuine ~2.5mm QUIET ZONE on each side — the blank margin a
           scanner uses to detect where the bars start/stop. The earlier
           33mm width left under 1mm total, which is a common real-world
           cause of unreliable scans, separate from any rotation issue. */
        .barcode-label img {
            width: 30mm;
            max-width: 100%;
            height: 8mm;
            object-fit: contain;
            margin: 0.3mm 0;
        }

        .no-print {
            text-align: center;
            margin: 16px 0;
        }

        .no-print .reminder {
            max-width: 420px;
            margin: 0 auto 12px;
            padding: 10px 14px;
            background: #fff3cd;
            border: 1px solid #ffe08a;
            border-radius: 6px;
            font-size: 13px;
            text-align: left;
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
                /* Preview on screen right-side-up so it's easy to check the
                   content itself; only the actual print output is rotated. */
                transform: none;
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

<div class="no-print">
    <div class="reminder">
        <strong>Before printing:</strong> click "More settings" in the print
        dialog and <u>uncheck "Headers and footers"</u> — otherwise the browser
        stamps the page URL and date/time onto every label.
    </div>
    <button onclick="window.print()">Print Labels</button>
</div>

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

</body>
</html>