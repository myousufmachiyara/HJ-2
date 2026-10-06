<!DOCTYPE html>
@php
    /*
     | Printer resolution in dots per inch. 203 = Zebra GC420 and almost
     | every 2-inch label printer. Change to 300 ONLY for a 300 dpi printer.
     */
    $printerDpi = 203;

    /*
     | Code 128 bars for every barcode on the page, sized in whole printer
     | dots. $dotsPerModule = how many dots wide the thinnest bar is:
     |   - as wide as fits in 44mm (the width the barcode image had), max 4
     |   - a long code that would drop to 1 dot is allowed the full label
     |     width (keeping a quiet zone) so it can still print at 2 dots
     |   - 1 dot = too thin to scan reliably → warned on the print page
     */
    $labelDots  = (int) round(2 * $printerDpi);            // 2in label
    $targetDots = (int) floor(44 / 25.4 * $printerDpi);    // 44mm
    $barcodeBars = [];
    $thinCodes   = [];
    foreach ($barcodes as $b) {
        $text = (string) $b['barcodeText'];
        if (isset($barcodeBars[$text])) continue;
        try {
            $code    = (new \Picqer\Barcode\Types\TypeCode128())->getBarcode($text);
            $modules = $code->getWidth();

            $dotsPerModule = max(1, min(4, intdiv($targetDots, max(1, $modules))));
            if ($dotsPerModule < 2 && ($modules + 20) * 2 <= $labelDots) $dotsPerModule = 2;
            if ($dotsPerModule < 2) $thinCodes[] = $text;

            // Everything below is in printer dots, counted from the LEFT EDGE
            // of the label (the drawing spans the whole label width, so it
            // starts exactly on the printer's dot grid — the browser only
            // positions drawings on whole CSS pixels, and "label edge" is
            // the one place where a CSS pixel and a printer dot line up).
            // Each bar is also pulled in by a hair ($inset) on both sides so
            // it fills exactly its own dots whichever way the printer driver
            // rounds an edge that sits right on a dot boundary.
            $inset = 0.12;
            $width = $modules * $dotsPerModule;
            $x     = intdiv($labelDots - $width, 2);   // centred, whole dots
            $path  = '';
            foreach ($code->getBars() as $bar) {
                $w = $bar->getWidth() * $dotsPerModule;
                if ($bar->isBar() && $w > 0) $path .= 'M' . ($x + $inset) . ' 0h' . ($w - 2 * $inset) . 'v1h-' . ($w - 2 * $inset) . 'z';
                $x += $w;
            }
            $barcodeBars[$text] = $path;
        } catch (\Throwable $e) {
            $barcodeBars[$text] = null;   // falls back to the PNG image
        }
    }
@endphp
<html data-ink="1">
<head>
    <title>Print Barcodes</title>
    <style>
        @page {
            size: 2in 0.9in;   /* ← label width x height */
            margin: 0;
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
            font-weight: 800;
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
            font-size: 10px;
            font-weight: 500;
        }

        .barcode-label small {
            font-size: 7px;
        }

        .barcode-label .brand {
            font-size: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #000;
        }

        /* Human-readable barcode number in a monospace face with a little
           letter-spacing — standard convention on real retail tags, and
           noticeably easier to read/key-in manually than the default
           proportional font at this size. */
        .barcode-label .barcode-number {
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            margin-top: 0.5mm;
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
            width: 44mm;
            max-width: 100%;
            height: 9mm;
            object-fit: fill;
            margin: 0.4mm 0 0.2mm;
        }

        /* ────────────────────────────────────────────────────────────
           BARCODE BARS — drawn as solid black vector bars, NOT a stretched
           picture.

           The old PNG was 2 pixels per bar-module, stretched to 44mm =
           about 2.25 printer dots per module. A printer cannot print a
           quarter of a dot, so some bars came out 2 dots wide and others
           3 — the widths no longer matched the code and scanners failed.

           Now every module is a WHOLE number of printer dots (worked out
           per barcode at the top of this file from $printerDpi), so each bar is
           exactly as wide as the code says. Height and margins are the
           same as the old image, so nothing else on the label moves.
           ──────────────────────────────────────────────────────────── */
        .barcode-label .barcode-bars {
            display: block;
            flex: none;
            /* full label width (cancels the label's 1.1mm side padding) —
               the bars themselves are centred inside it, at most 44mm wide */
            width: 2in;
            max-width: none;
            height: 9mm;
            margin: 0.4mm -1.1mm 0.2mm;
        }

        /* ────────────────────────────────────────────────────────────
           INK DARKNESS — text.

           A thermal printer has no grey: a dot is burnt or it is not.
           Thin letter strokes are only 1–2 dots wide and print faint.

           The text is "overstruck": the same real text is printed again
           exactly ONE PRINTER DOT to the side, so every stroke gets one
           dot thicker. It stays real text all the way to the printer.
           (The earlier outline trick, -webkit-text-stroke, made the
           browser send the letters as drawn shapes instead of text, which
           this printer rendered lighter — it is gone.)

           Pick the level with the "Darkness" box on the print page:
             Normal     – no overstrike
             Dark       – every line one dot thicker            (default)
             Extra dark – name + number also one dot taller
           Nothing here changes any size or position.

           NOTE: how hard the head burns is a PRINTER setting. If even
           "Extra dark" is pale, raise Darkness/Density and lower Speed in
           the printer's Printing Preferences.
           ──────────────────────────────────────────────────────────── */
        :root {
            --dot: calc(1in / {{ $printerDpi }});   /* one printer dot */
        }

        .barcode-label,
        .barcode-label * {
            color: #000 !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        html[data-ink="1"] .barcode-label strong,
        html[data-ink="1"] .barcode-label small,
        html[data-ink="2"] .barcode-label small:not(.barcode-number) {
            text-shadow: var(--dot) 0 0 #000;
        }

        html[data-ink="2"] .barcode-label strong,
        html[data-ink="2"] .barcode-label .barcode-number {
            text-shadow: var(--dot) 0 0 #000,
                         0 var(--dot) 0 #000,
                         var(--dot) var(--dot) 0 #000;
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

            /* ── ONE LABEL = ONE STICKER ───────────────────────────────
               A second (blank) sticker was fed whenever the label box was
               even a hair taller than the page the printer really uses
               (driver stock a fraction under 0.9in, or rounding): the
               overflow spilled onto a new page.
               So, when printing only, each label is sized to the ACTUAL
               page (100vh = the printed page height) minus a 0.3mm
               safety margin, never more than 0.9in, and a page break is
               forced only BETWEEN labels — never after the last one. */
            html, body {
                height: auto;
            }
            .barcode-label {
                height: min(0.9in, calc(100vh - 0.3mm));
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .barcode-label:not(:last-child) {
                break-after: page;
                page-break-after: always;
            }
            .barcode-label:last-child {
                break-after: avoid;
                page-break-after: avoid;
            }

            /* hard bar edges on paper (on screen they are smoothed, because
               a screen pixel is coarser than a printer dot) */
            .barcode-label .barcode-bars {
                shape-rendering: crispEdges;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">Print Labels</button>
    &nbsp; Darkness:
    <select id="inkLevel">
        <option value="0">Normal</option>
        <option value="1" selected>Dark</option>
        <option value="2">Extra dark</option>
    </select>
    <div class="reminder" style="margin-top:12px">
        In the print window keep <b>Scale: 100 / Default</b> and <b>Margins: None</b> —
        any other scale changes the bar widths and the barcode may not scan.
    </div>
    @if(count($thinCodes))
        <div class="reminder" style="background:#f8d7da;border-color:#f1aeb5">
            Too long for this label — bars will be very thin and may not scan:
            <b>{{ implode(', ', $thinCodes) }}</b>. Use a shorter barcode for these.
        </div>
    @endif
    <script>
        // remembers the chosen darkness on this computer
        (function () {
            var sel = document.getElementById('inkLevel'), saved = null;
            try { saved = localStorage.getItem('labelInk'); } catch (e) {}
            if (saved === '0' || saved === '1' || saved === '2') sel.value = saved;
            function apply() {
                document.documentElement.setAttribute('data-ink', sel.value);
                try { localStorage.setItem('labelInk', sel.value); } catch (e) {}
            }
            sel.addEventListener('change', apply);
            apply();
        })();
    </script>
</div>

@foreach($barcodes as $barcode)
    <div class="barcode-label">
        <strong>{{ $barcode['product'] }}</strong>
        @if(!empty($barcode['variation']))
            <small>{{ $barcode['variation'] }}</small>
        @endif
        @php $bars = $barcodeBars[(string) $barcode['barcodeText']] ?? null; @endphp
        @if($bars)
            <svg class="barcode-bars" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="barcode"
                 viewBox="0 0 {{ $labelDots }} 1" preserveAspectRatio="none"><path d="{{ $bars }}" fill="#000"/></svg>
        @else
            <img src="data:image/png;base64,{{ $barcode['barcodeImage'] }}" alt="barcode">
        @endif
        <small class="barcode-number">{{ $barcode['barcodeText'] }}</small>
    </div>
@endforeach

</body>
</html>