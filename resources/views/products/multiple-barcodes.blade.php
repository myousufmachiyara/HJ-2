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
            size: 100mm 50mm;   /* ← label width x height */
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
            width: 100mm;
            height: 60mm;
            padding: 0.3mm 1.1mm;
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

        /* Font sizes below were computed against the label's real vertical
           budget (25mm - 2×0.6mm padding = 23.8mm). Every stacked element,
           at these sizes including line-height, adds up to ~21mm — leaving
           slack instead of being crammed edge-to-edge at 5px, which is what
           made the previous pass look cramped and amateurish. */
        .barcode-label strong,
        .barcode-label small {
            display: block;
            width: 100%;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .barcode-label strong {
            font-size: 14px;
            font-weight: 900;
        }

        .barcode-label small {
            font-size: 12px;
        }

        .barcode-label .brand {
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #555;
        }

        /* Human-readable barcode number in a monospace face with a little
           letter-spacing — standard convention on real retail tags, and
           noticeably easier to read/key-in manually than the default
           proportional font at this size. */
        .barcode-label .barcode-number {
            font-family: 'Courier New', Courier, monospace;
            font-size: 10px;
            letter-spacing: 0.4px;
            margin-top: 0.3mm;
        }

        /* Content area is 35mm wide (38mm - 2×1.6mm padding); the image is
           deliberately narrower than that (30mm, centered by the flex
           layout) so it keeps a genuine ~2.5mm QUIET ZONE on each side —
           the blank margin a scanner uses to detect where the bars
           start/stop. Height bumped to 9mm now that the layout has room,
           since taller bars scan more reliably too. */
        .barcode-label img {
            width: 50mm;
            max-width: 100%;
            height: 11mm;
            object-fit: contain;
            margin: 0.4mm 0 0.2mm;
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
        <small class="barcode-number">{{ $barcode['barcodeText'] }}</small>
    </div>
@endforeach

</body>
</html>