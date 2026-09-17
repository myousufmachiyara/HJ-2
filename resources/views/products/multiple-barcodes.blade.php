<!DOCTYPE html>
<html>
<head>
    <title>Print Barcodes</title>
    <style>
        /* ────────────────────────────────────────────────────────────
           THERMAL LABEL SIZE — corrected measurement: 38mm x 26mm. Must
           match the EXACT stock size configured in your printer driver
           (Control Panel → Devices & Printers → your thermal printer →
           Printing Preferences → Stock/Media size) or labels will
           misalign / split across labels.
           ──────────────────────────────────────────────────────────── */
        @page {
            size: 38mm 26mm;   /* ← label width x height */
            margin: 0;
        }

        /* No CSS rotation — the printer driver's own orientation setting
           now feeds the roll correctly, so the content prints right-way-up
           and horizontal without any transform here. If that driver
           setting ever changes and labels come out rotated again, that's
           the place to fix it, not here. */

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
            height: 26mm;
            padding: 0.3mm 1.1mm;
            margin: 0;
            display: flex;
            flex-direction: row;
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

        /* Font sizes computed against the label's real vertical budget
           (26mm - 2×0.3mm padding = 25.4mm usable). Every stacked element
           below, at these sizes including line-height and the barcode
           image, adds up to ~21.4mm — comfortable headroom instead of
           being crammed edge-to-edge, and noticeably bigger/easier to read
           than the original pass without overflowing and getting silently
           clipped by `overflow: hidden`. */
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
            font-size: 10px;
            font-weight: 700;
        }

        .barcode-label small {
            font-size: 8px;
        }

        .barcode-label .brand {
            font-size: 7px;
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
            font-size: 8px;
            letter-spacing: 0.4px;
            margin-top: 0.3mm;
        }

        /* Content area is 35.8mm wide (38mm - 2×1.1mm padding). The image
           is deliberately narrower than that (31mm, centered by the flex
           layout) so it keeps a genuine ~2.4mm QUIET ZONE on each side —
           the blank margin a scanner uses to detect where the bars
           start/stop. Fixed mm width on purpose (not a %) — a percentage
           here resolves against the flex column's content box rather than
           a predictable physical size, which would quietly erode the
           quiet zone the moment padding/layout changes. */
        .barcode-label img {
            width: 31mm;
            max-width: 100%;
            height: 10.5mm;
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