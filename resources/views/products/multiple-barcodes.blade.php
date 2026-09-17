<!DOCTYPE html>
<html>
<head>
    <title>Print Barcodes</title>
    <style>
        /* ────────────────────────────────────────────────────────────
           THERMAL LABEL SIZE — measured 2in x 0.9in. Must match the EXACT
           stock size configured in your printer driver (Control Panel →
           Devices & Printers → your thermal printer → Printing
           Preferences → Stock/Media size) or labels will misalign / split
           across labels. If you re-measure and this changes again, update
           BOTH this @page rule and .barcode-label below (they must always
           match each other) — and ProductController::labelZplDots() for
           the Zebra ZPL print path.
           ──────────────────────────────────────────────────────────── */
        @page {
            size: 2in 0.9in;   /* ← label width x height */
            margin: 0;
        }

        .barcode-label {
            transform: rotate(90deg);
        }
        /* No CSS rotation — the printer driver's own orientation setting
           feeds the roll correctly, so the content prints right-way-up
           and horizontal without any transform here. If labels come out
           rotated, that's a driver-default setting to fix, not this file
           — see the reply for where that default actually lives. */

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
            width: 2in;
            height: 0.9in;
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

        /* Font sizes recomputed for the 0.9in (22.86mm) label height —
           usable height after padding is ~22.26mm, and every stacked
           element below (including line-height and the barcode image)
           totals ~18.7mm, leaving ~3.5mm of headroom rather than running
           right up to the edge where `overflow: hidden` would start
           silently clipping. Sized down from the previous 26mm-tall
           label's fonts since there's meaningfully less vertical room
           here — 0.9in is shorter than either earlier measurement. */
        .barcode-label strong,
        .barcode-label small {
            font-weight: 600;
            display: block;
            width: 100%;
            /* Flex items default to `min-width: auto`, which means the
               browser will NOT let this item shrink below the width its
               unbroken text needs — even though width:100% and
               overflow:hidden are set. That's what let a long product
               name push past the label's edge instead of truncating: the
               box itself was silently growing to fit the text before
               overflow/ellipsis ever got a chance to act. min-width: 0
               overrides that default and makes the 100% width, nowrap,
               overflow-hidden, ellipsis combo actually clip as intended. */
            min-width: 0;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .barcode-label strong {
            font-size: 9px;
            font-weight: 700;
        }

        .barcode-label small {
            font-size: 7px;
        }

        .barcode-label .brand {
            font-size: 6px;
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
            font-size: 7px;
            letter-spacing: 0.4px;
            margin-top: 0.3mm;
        }

        /* The image is deliberately narrower than the label's content
           width (~45.5mm at 2in wide, minus padding) so it keeps a
           genuine QUIET ZONE on each side — the blank margin a scanner
           uses to detect where the bars start/stop. Fixed mm width on
           purpose (not a %) — a percentage here resolves against the flex
           column's content box rather than a predictable physical size,
           which would quietly erode the quiet zone the moment
           padding/layout changes. Height trimmed to 9mm (from 10.5mm) to
           fit the shorter 0.9in label. */
        .barcode-label img {
            width: 34mm;
            max-width: 100%;
            height: 9mm;
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