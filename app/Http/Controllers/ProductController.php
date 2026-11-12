<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\ChartOfAccounts;
use App\Models\Attribute;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\MeasurementUnit;
use App\Models\ProductVariation;
use App\Models\AttributeValue;
use App\Models\ProductVariationAttributeValue;
use App\Models\ProductImport;                 // ← NEW
use App\Jobs\PrepareProductImportJob;          // ← NEW
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;            // ← NEW
use Picqer\Barcode\BarcodeGeneratorPNG;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    public function index()
    {
        // The table loads its rows page by page through data() — never the whole catalogue at once.
        $categories = ProductCategory::all();
        // stores products can be pushed to (Push to Shopify button)
        $shopifyStores = auth()->user()?->can('shopify_stores.edit')
            ? \App\Models\ShopifyStore::orderBy('shop_name')->get()->filter->isConnected()->values()
            : collect();
        // products an earlier import saved with Excel formula text as SKU / name
        $formulaProducts = Product::where('sku', 'like', '=%')->orWhere('name', 'like', '%[=%')->count();
        // last bulk import: keep tracking it if still running, show its result if just finished
        $lastImport = ProductImport::where('created_at', '>', now()->subDay())->latest('id')->first();
        return view('products.index', compact('categories', 'shopifyStores', 'formulaProducts', 'lastImport'));
    }

    /**
     * Remove products a bulk import saved with Excel formula text (SKU "=LOWER(…)").
     * Rows never used in any entry are removed completely, so the corrected sheet can be
     * imported again with the same names; used ones are only soft-deleted.
     */
    public function cleanupFormulaProducts()
    {
        $removed = 0; $kept = 0;
        Product::where('sku', 'like', '=%')->orWhere('name', 'like', '%[=%')->with('variations')->get()
            ->each(function (Product $p) use (&$removed, &$kept) {
                $softDelete = function () use ($p, &$kept) {
                    $p->refresh();
                    $p->variations()->get()->each->delete();
                    $p->update(['name' => \Illuminate\Support\Str::limit($p->name, 200, '') . ' [deleted ' . $p->id . ']']);
                    $p->delete();
                    $kept++;
                };
                if (\App\Models\StockLedger::where('product_id', $p->id)->exists()) { $softDelete(); return; }
                try {
                    DB::transaction(function () use ($p) {
                        foreach ($p->variations as $v) {
                            $v->attributeValues()->detach();
                            $v->forceDelete();
                        }
                        $p->images()->delete();
                        $p->forceDelete();
                    });
                    $removed++;
                } catch (\Illuminate\Database\QueryException $e) {
                    $softDelete();   // still referenced somewhere (invoice line, etc.)
                }
            });

        return redirect()->route('products.index')->with('success', "Removed {$removed} product(s) imported with formulas"
            . ($kept ? " ({$kept} already used in entries were soft-deleted)" : '') . '. Now import the corrected sheet again.');
    }

    public function barcodeSelection()
    {
        // rows are fetched page by page by barcodeData()
        // items whose barcode is too long for the label — their labels print a short label code
        $longCount = \App\Services\ShortBarcode::countLong();
        return view('products.barcode-selection', compact('longCount'));
    }

    // ─────────────────────────────────────────────────────────────────
    //  Fast lists: one page at a time, searched / sorted in the database
    // ─────────────────────────────────────────────────────────────────

    /** Escape % _ \ so a typed search is matched literally. */
    private function likeEscape(string $s): string
    {
        return addcslashes($s, '%_\\');
    }

    /**
     * Every typed word must match (AND); a word matches when ANY of the columns contains it.
     * $contains: matched anywhere ("%word%"), $startsWith: matched from the start ("word%", can use the index).
     */
    private function searchWords($query, string $term, array $contains, array $startsWith = []): void
    {
        foreach (preg_split('/\s+/', trim($term), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $any = $this->likeEscape($word);
            $query->where(function ($w) use ($any, $contains, $startsWith) {
                foreach ($contains as $col)   $w->orWhere($col, 'like', '%' . $any . '%');
                foreach ($startsWith as $col) $w->orWhere($col, 'like', $any . '%');
            });
        }
    }

    /** DataTables paging values, with a safety cap. */
    private function pageWindow(Request $request, int $max = 200): array
    {
        $length = (int) $request->input('length', 100);
        $length = $length < 1 ? 100 : min($length, $max);
        return [max(0, (int) $request->input('start', 0)), $length];
    }

    /** Products list — server-side DataTables feed (one page of rows, never the whole catalogue). */
    public function data(Request $request)
    {
        $term = trim((string) data_get($request->input('search'), 'value', ''));

        $filtered = Product::query();
        if ($term !== '') {
            foreach (preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $any = $this->likeEscape($word);
                $filtered->where(function ($w) use ($any) {
                    $w->where('products.name', 'like', "%{$any}%")
                      ->orWhere('products.sku', 'like', "%{$any}%")
                      ->orWhere('products.brand', 'like', "%{$any}%")
                      ->orWhere('products.barcode', 'like', "{$any}%")
                      ->orWhereHas('variations', fn ($v) => $v->where(
                          fn ($x) => $x->where('sku', 'like', "%{$any}%")->orWhere('barcode', 'like', "{$any}%")
                      ));
                });
            }
        }

        // "Tick all matching" on the Shopify bar: only the ids that can be pushed
        if ($request->boolean('ids_only')) {
            return response()->json([
                'ids' => (clone $filtered)->where('item_type', 'fg')->where('is_active', 1)->whereNull('shopify_product_id')->pluck('id'),
            ]);
        }

        $total       = Product::count();
        $filteredCnt = (clone $filtered)->count();

        // sorting — only columns that make sense; default = oldest first (as before)
        $column = (string) $request->input('columns.' . (int) $request->input('order.0.column', -1) . '.data', '');
        $dir    = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        match ($column) {
            'name'     => $filtered->orderBy('products.name', $dir),
            'brand'    => $filtered->orderBy('products.brand', $dir),
            'sku'      => $filtered->orderBy('products.sku', $dir),
            'shopify'  => $filtered->orderBy('products.shopify_product_id', $dir),
            'category' => $filtered->orderBy(ProductCategory::select('name')->whereColumn('product_categories.id', 'products.category_id'), $dir),
            default    => null,
        };
        $filtered->orderBy('products.id');

        [$start, $length] = $this->pageWindow($request);
        $page = $filtered->with(['category:id,name', 'shopifyStore:id,shop_name', 'images'])->skip($start)->take($length)->get();

        return response()->json([
            'draw'            => (int) $request->input('draw'),
            'recordsTotal'    => $total,
            'recordsFiltered' => $filteredCnt,
            'data'            => $page->map(fn ($p) => [
                'id'        => $p->id,
                'name'      => $p->name,
                'brand'     => $p->brand,
                'sku'       => $p->sku,
                'category'  => $p->category?->name,
                'image'     => $p->images->first()?->url,
                'shopify'   => $p->shopify_product_id ? 'on' : (($p->item_type === 'fg' && $p->is_active) ? 'pending' : 'none'),
                'store'     => $p->shopifyStore?->shop_name,
                'can_push'  => $p->item_type === 'fg' && $p->is_active && !$p->shopify_product_id,
                'is_raw'    => $p->item_type === 'raw',
                'edit_url'  => route('products.edit', $p->id),
                'delete_url'=> route('products.destroy', $p->id),
            ])->values(),
        ]);
    }

    /** Barcode page — server-side DataTables feed of variations. */
    public function barcodeData(Request $request)
    {
        $term = trim((string) data_get($request->input('search'), 'value', ''));

        $base = ProductVariation::query()
            ->join('products', 'products.id', '=', 'product_variations.product_id')
            ->whereNull('products.deleted_at');                       // skip variations of deleted products

        $filtered = clone $base;
        if ($term !== '') {
            $this->searchWords($filtered, $term,
                ['products.name', 'product_variations.sku', 'products.sku'],
                ['product_variations.barcode', 'products.barcode']);
        }

        $total       = (clone $base)->count();
        $filteredCnt = (clone $filtered)->count();

        $column = (string) $request->input('columns.' . (int) $request->input('order.0.column', -1) . '.data', '');
        $dir    = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        match ($column) {
            'product' => $filtered->orderBy('products.name', $dir),
            'sku'     => $filtered->orderBy('product_variations.sku', $dir),
            'barcode' => $filtered->orderByRaw('COALESCE(product_variations.barcode, products.barcode) ' . $dir),
            default   => null,
        };
        $filtered->orderBy('product_variations.id');

        [$start, $length] = $this->pageWindow($request);
        $page = $filtered->select([
                'product_variations.id', 'product_variations.sku', 'product_variations.barcode', 'product_variations.selling_price',
                'products.name as product_name', 'products.barcode as product_barcode', 'products.selling_price as product_price',
            ])->skip($start)->take($length)->get();

        return response()->json([
            'draw'            => (int) $request->input('draw'),
            'recordsTotal'    => $total,
            'recordsFiltered' => $filteredCnt,
            'data'            => $page->map(fn ($r) => [
                'id'      => $r->id,
                'product' => $r->product_name,
                'sku'     => $r->sku,
                'barcode' => $r->barcode ?? $r->product_barcode ?? '-',
                // size price, else the product's default (same rule as ProductVariation::salePrice)
                'price'   => number_format((float) ($r->selling_price ?? $r->product_price ?? 0), 2),
            ])->values(),
        ]);
    }

    /**
     * Select2 search feed for item dropdowns (reports): products + their sizes, 25 products per page.
     * Replaces a <select> that used to hold every product and every size at once.
     */
    public function itemSearch(Request $request)
    {
        $term = trim((string) $request->input('q', ''));
        $page = max(1, (int) $request->input('page', 1));
        $per  = 25;

        $q = Product::query()->orderBy('name')->orderBy('id');
        if ($term !== '') {
            foreach (preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $any = $this->likeEscape($word);
                $q->where(function ($w) use ($any) {
                    $w->where('name', 'like', "%{$any}%")
                      ->orWhere('sku', 'like', "%{$any}%")
                      ->orWhere('barcode', 'like', "{$any}%")
                      ->orWhereHas('variations', fn ($v) => $v->where(
                          fn ($x) => $x->where('sku', 'like', "%{$any}%")->orWhere('barcode', 'like', "{$any}%")
                      ));
                });
            }
        }

        $products = $q->with('variations:id,product_id,sku')->skip(($page - 1) * $per)->take($per + 1)->get(['id', 'name', 'sku']);
        $more     = $products->count() > $per;

        return response()->json([
            'results'    => $products->take($per)->map(fn ($p) => [
                'text'     => "{$p->name} ({$p->sku})",
                'children' => collect([['id' => (string) $p->id, 'text' => 'All sizes', 'full' => "{$p->name} ({$p->sku}) — all sizes"]])
                    ->concat($p->variations->sortBy('id')->map(fn ($v) => [
                        'id' => "{$p->id}-{$v->id}", 'text' => "↳ {$v->sku}", 'full' => "{$p->name} — {$v->sku}",
                    ]))->values(),
            ])->values(),
            'pagination' => ['more' => $more],
        ]);
    }

    /**
     * Validate + expand the barcode-selection form's posted variation ids
     * and quantities into one row per physical label to print (qty
     * duplicated), shared by both the browser-print (PNG) and Zebra ZPL
     * printing flows so they can never drift apart on price/brand/fallback
     * logic. Returns null (after writing validation errors onto $errors)
     * if validation fails.
     */
    private function collectBarcodeRows(Request $request, &$validationRedirect = null): ?array
    {
        // The page sends its ticked rows as one JSON field ({"id": qty, ...}) so a big selection
        // never runs into PHP's max_input_vars limit.
        if ($request->filled('items_json')) {
            $picked = json_decode((string) $request->input('items_json'), true);
            if (is_array($picked)) {
                $request->merge([
                    'selected_variations' => array_map('intval', array_keys($picked)),
                    'quantity'            => array_combine(array_map('intval', array_keys($picked)), array_map('intval', array_values($picked))),
                ]);
            }
        }

        $validator = Validator::make($request->all(), [
            'selected_variations'   => 'required|array|min:1',
            'selected_variations.*' => 'exists:product_variations,id',
            'quantity'              => 'required|array',
        ]);

        if ($validator->fails()) {
            Log::error('Barcode generation validation failed', [
                'errors'  => $validator->errors(),
                'request' => $request->all(),
            ]);
            $validationRedirect = back()->withErrors($validator)->withInput();
            return null;
        }

        $rows = [];

        $found = ProductVariation::with('product')->whereIn('id', $request->selected_variations)->get()->keyBy('id');

        foreach ($request->selected_variations as $variationId) {
            $qty       = max(1, (int)($request->quantity[$variationId] ?? 1));
            $variation = $found[$variationId] ?? ProductVariation::with('product')->findOrFail($variationId);

            // Manual barcode: variation barcode first, then fall back to the
            // product's own barcode, then SKU as a last resort.
            $barcodeText  = $variation->barcode ?? $variation->product->barcode ?? $variation->sku ?? 'NO-BARCODE';
            // too long to scan on the 2in label → print the short label code (stored barcode / SKU stay as they are)
            $barcodeText  = \App\Services\ShortBarcode::forLabel((int) $variation->id, (string) $barcodeText);
            $price        = number_format($variation->salePrice(), 2);          // size price, else product default
            $comparePrice = $variation->comparePrice() !== null ? number_format($variation->comparePrice(), 2) : null;
            $brand        = $variation->product->brand ?? '';

            for ($i = 0; $i < $qty; $i++) {
                $rows[] = [
                    'product'      => $variation->product->name,
                    'brand'        => $brand,
                    'variation'    => $variation->name ?? '',
                    'barcodeText'  => $barcodeText,
                    'price'        => $price,
                    'comparePrice' => $comparePrice,
                    'sku'          => $variation->sku,
                ];
            }
        }

        return $rows;
    }

    public function generateMultipleBarcodes(Request $request)
    {
        try {
            $rows = $this->collectBarcodeRows($request, $validationRedirect);
            if ($rows === null) {
                return $validationRedirect;
            }

            // Generate each variation's barcode PNG once and reuse it across
            // its duplicated qty rows, rather than re-rendering per copy.
            $imageCache = [];
            $generator  = new BarcodeGeneratorPNG();
            $barcodes   = [];

            foreach ($rows as $row) {
                if (!isset($imageCache[$row['barcodeText']])) {
                    // Sized to match the label's 30mm x 9mm barcode image box
                    // (~240x72px at a thermal printer's ~203 DPI) so the source
                    // PNG isn't being upscaled and blurred at print time.
                    //
                    // IMPORTANT — this controls sharpness only, not the physical
                    // width of each bar on paper: object-fit:contain always
                    // scales the whole image to fit 30mm regardless of
                    // widthFactor. What actually determines how wide (and how
                    // scannable) each bar is on a 38mm label is how much text
                    // gets encoded — a short code like "8901234" prints with
                    // comfortably wide bars; a long generated id like
                    // "SHP-3-B-45678901234" gets compressed into the same 30mm
                    // and comes out with much thinner, less forgiving bars.
                    // For reliable scans at this label size, prefer short
                    // numeric/alphanumeric barcodes over long auto-generated
                    // fallback ids wherever you control the source value.
                    $imageCache[$row['barcodeText']] = base64_encode(
                        $generator->getBarcode($row['barcodeText'], $generator::TYPE_CODE_128, widthFactor: 2, height: 72)
                    );
                }

                $barcodes[] = $row + ['barcodeImage' => $imageCache[$row['barcodeText']]];
            }

            return view('products.multiple-barcodes', compact('barcodes'));

        } catch (\Throwable $e) {
            Log::error('Exception while generating barcodes', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->with('error', 'Something went wrong while generating barcodes.');
        }
    }

    /**
     * Physical label geometry in ZPL dots. THIS is the one place to correct
     * if the real label size changes — measured (via ruler, in inches) as
     * 2in x 0.9in, matching resources/views/products/multiple-barcodes.
     * blade.php's @page size. Keeping both in inches (rather than
     * converting to mm) avoids re-introducing the kind of rounding/typo
     * drift that happened going mm → cm earlier in this label's history.
     * 203 dpi is the Zebra GC420's native resolution (both GC420d/GC420t).
     */
    private function labelZplDots(): array
    {
        $widthIn   = 2;
        $heightIn  = 0.9;
        $dpi       = 203;
        $dotsPerMm = $dpi / 25.4; // ≈ 8, used for margins below (defined in mm to match the CSS label's padding)

        return [
            'width'  => (int) round($widthIn * $dpi),
            'height' => (int) round($heightIn * $dpi),
            'dpm'    => $dotsPerMm,
        ];
    }

    // ^ and ~ are ZPL's own command/control prefixes — stray occurrences in
    // label text would corrupt the format stream. Product names essentially
    // never legitimately contain them, so stripping is safe.
    private function zplEscape(string $text): string
    {
        return str_replace(['^', '~', '\\'], '', $text);
    }

    // ZPL has no CSS text-overflow:ellipsis equivalent, so long names are
    // truncated deterministically server-side instead — this replaces the
    // browser-side min-width/overflow/ellipsis trio entirely for this flow.
    private function zplTruncate(string $text, int $maxChars): string
    {
        $text = trim($text);
        if ($maxChars <= 0 || mb_strlen($text) <= $maxChars) {
            return $text;
        }
        return mb_substr($text, 0, max(0, $maxChars - 1)) . '…';
    }

    /**
     * Build one self-contained ^XA...^XZ ZPL label for a single barcode row.
     * Coordinates are computed explicitly in dots (no CSS flexbox/overflow
     * quirks to fight), and the stack is vertically centered the same way
     * the browser-print label is, just computed exactly instead of relying
     * on the browser to lay it out.
     */
    private function buildZplLabel(array $row): string
    {
        $dots         = $this->labelZplDots();
        $width        = $dots['width'];
        $height       = $dots['height'];
        $dpm          = $dots['dpm'];
        $marginX      = (int) round(1.1 * $dpm); // matches the browser-print label's horizontal padding
        $marginTop    = (int) round(0.3 * $dpm); // matches its vertical padding
        $contentWidth = $width - (2 * $marginX);
        $usableHeight = $height - (2 * $marginTop);

        $brandFont     = 20;
        $productFont   = 26;
        $variationFont = 20;
        $barHeight     = 70;      // Code128 bar height in dots
        $barcodeBlock  = $barHeight + 24; // + the printer's own interpretation-line text under the bars
        $gap           = 4;

        $brand     = $this->zplTruncate($this->zplEscape($row['sku'] ?? ''),   // variation SKU (replaces the brand line)
             (int) floor($contentWidth / ($brandFont * 0.6)));
        $product   = $this->zplTruncate($this->zplEscape($row['product'] ?? ''), (int) floor($contentWidth / ($productFont * 0.6)));
        $variation = $this->zplTruncate($this->zplEscape($row['variation'] ?? ''), (int) floor($contentWidth / ($variationFont * 0.6)));
        $barcodeText = $this->zplEscape($row['barcodeText'] ?? '');

        $totalHeight = $barcodeBlock;
        $totalHeight += $product !== '' ? $productFont + $gap : 0;
        $totalHeight += $brand !== '' ? $brandFont + $gap : 0;
        $totalHeight += $variation !== '' ? $variationFont + $gap : 0;

        $y = $marginTop + max(0, (int) round(($usableHeight - $totalHeight) / 2));

        $fields = [];

        if ($brand !== '') {
            $fields[] = "^FO{$marginX},{$y}^A0N,{$brandFont},{$brandFont}^FD{$brand}^FS";
            $y += $brandFont + $gap;
        }

        $fields[] = "^FO{$marginX},{$y}^A0N,{$productFont},{$productFont}^FD{$product}^FS";
        $y += $productFont + $gap;

        if ($variation !== '') {
            $fields[] = "^FO{$marginX},{$y}^A0N,{$variationFont},{$variationFont}^FD{$variation}^FS";
            $y += $variationFont + $gap;
        }

        // Code128 drawn by the PRINTER itself (crisp, no browser/driver re-drawing).
        //  - mode "A" = automatic: digits are packed two per symbol (Code C), so numeric
        //    codes are about half as wide and get thicker, more scannable bars;
        //  - ^BY picks the widest bar (up to 3 dots) that still leaves a 10-bar quiet zone
        //    on each side of the 2in label; centred, with the printer's own
        //    human-readable line under the bars.
        try {
            $modules = (new \Picqer\Barcode\Types\TypeCode128())->getBarcode($barcodeText)->getWidth();
        } catch (\Throwable $e) {
            $modules = 11 * (strlen($barcodeText) + 3) + 2;
        }
        $bar = max(1, min(3, intdiv($width, $modules + 20)));
        $x   = max(0, intdiv($width - $modules * $bar, 2));
        $fields[] = "^BY{$bar},3,{$barHeight}";
        $fields[] = "^FO{$x},{$y}^BCN,{$barHeight},Y,N,N,A^FD{$barcodeText}^FS";

        $body = implode("\n", $fields);

        // ^CI28 switches the printer to UTF-8 so brand/product names with
        // non-ASCII characters (e.g. Rs., accented letters) print correctly.
        return "^XA\n^CI28\n^PW{$width}\n^LL{$height}\n{$body}\n^XZ";
    }

    /**
     * Same selection form as generateMultipleBarcodes(), but instead of
     * rendering PNG barcode images into an HTML page for the browser's
     * print dialog, this builds native ZPL and hands it to the QZ Tray
     * print page, which sends it straight to the Zebra printer — no
     * browser/OS page-size negotiation, no CSS rotation, no image
     * upscaling. See products/print-zpl.blade.php.
     */
    public function generateZplLabels(Request $request)
    {
        try {
            $rows = $this->collectBarcodeRows($request, $validationRedirect);
            if ($rows === null) {
                return $validationRedirect;
            }

            $zplBlocks = array_map(fn ($row) => $this->buildZplLabel($row), $rows);

            return view('products.print-zpl', [
                'barcodes'  => $rows,
                'zplBlocks' => $zplBlocks,
            ]);

        } catch (\Throwable $e) {
            Log::error('Exception while generating ZPL labels', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);
            return back()->with('error', 'Something went wrong while generating the Zebra labels.');
        }
    }

    public function create()
    {
        $categories    = ProductCategory::all();
        $subcategories = ProductSubcategory::all();
        $attributes    = Attribute::with('values')->get();
        $units         = MeasurementUnit::all();
        $vendors       = ChartOfAccounts::whereIn('account_type', ['customer', 'vendor'])->orderBy('name')->get();
        $fabrics       = Product::where('item_type', 'raw')->orderBy('name')->get(['id', 'name', 'sku']);

        $pannaAttr  = \App\Services\FabricSetup::pannaAttribute();
        $fgArticles = Product::with('variations:id,product_id,sku')->where('item_type', 'fg')->orderBy('name')->get(['id', 'name', 'sku']);

        return view('products.create', compact('categories', 'subcategories', 'attributes', 'units', 'vendors', 'fabrics', 'pannaAttr', 'fgArticles'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'               => 'required|string|max:255|unique:products,name',
            'category_id'        => 'required|exists:product_categories,id',
            'subcategory_id'     => 'nullable|exists:product_subcategories,id',
            'vendor_id'          => 'nullable|exists:chart_of_accounts,id',
            'brand'              => 'nullable|string|max:255',
            'sku'                => 'nullable|string|unique:products,sku',   // blank = auto {CATCODE}-{00001}
            'barcode'            => 'nullable|string|unique:products,barcode',
            'sku_opening_date'   => 'nullable|date',
            'description'        => 'nullable|string',
            'measurement_unit'   => 'required|exists:measurement_units,id',
            'item_type'          => 'required|in:fg,raw,service',
            'weight'             => 'nullable|numeric|min:0',
            'cmt_cost'           => 'nullable|numeric',
            'cost_price'         => 'nullable|numeric',
            'consumption'        => 'nullable|numeric|min:0',
            'fabric_id'          => 'nullable|exists:products,id',
            'selling_price'      => 'nullable|numeric',
            'compare_at_price'   => 'nullable|numeric|min:0',
            'opening_stock'      => 'nullable|numeric',
            'reorder_level'      => 'nullable|numeric',
            'max_stock_level'    => 'nullable|numeric',
            'minimum_order_qty'  => 'nullable|numeric',
            'is_active'          => 'boolean',
            'prod_att.*'         => 'nullable|image|mimes:jpeg,png,jpg,webp',
            'variations.*.barcode' => 'nullable|string|distinct|unique:product_variations,barcode',
            'variations.*.selling_price'    => 'nullable|numeric|min:0',
            'variations.*.compare_at_price' => 'nullable|numeric|min:0',
        ], [
            'variations.*.barcode.unique'   => 'Barcode :input is already used by another variation.',
            'variations.*.barcode.distinct' => 'The same barcode is typed on two variations.',
        ]);

        // timing of each step → storage/logs/laravel.log ("[Product Store] timing"), to see where a slow save spends its time
        $t0 = microtime(true); $marks = []; DB::enableQueryLog();
        $mark = function (string $step) use (&$marks, $t0) { $marks[$step] = (int) round((microtime(true) - $t0) * 1000); };

        DB::beginTransaction();
        try {
            $data = $request->only([
                'name', 'category_id', 'subcategory_id', 'vendor_id', 'brand',
                'sku', 'barcode', 'sku_opening_date', 'description',
                'measurement_unit', 'item_type', 'weight',
                'cmt_cost', 'cost_price',
                'opening_stock', 'selling_price', 'compare_at_price', 'consumption', 'fabric_id',
                'reorder_level', 'max_stock_level', 'minimum_order_qty', 'is_active',
            ]);
            $autoSku = blank($data['sku'] ?? null);
            if ($autoSku) {
                $data['sku'] = Product::generateSku((int) $data['category_id']);
            }
            // barcode left blank = a random unique number (never a copy of the SKU)
            if (blank($data['barcode'] ?? null)) {
                $data['barcode'] = \App\Services\BarcodeGenerator::generate();
            }
            $data['opening_stock'] = $data['opening_stock'] ?? 0;
            foreach (['cmt_cost', 'cost_price', 'selling_price', 'consumption', 'reorder_level', 'max_stock_level', 'minimum_order_qty'] as $num) {
                if (array_key_exists($num, $data) && $data[$num] === null) unset($data[$num]);
            }

            $mark('sku+barcode');
            $product = Product::create($data);
            $mark('product row');

            Log::info('[Product Store] Product created', ['product_id' => $product->id]);

            if ($request->hasFile('prod_att')) {
                foreach ($request->file('prod_att') as $image) {
                    $path = $image->store('products', 'public');
                    $product->images()->create(['image_path' => $path]);
                }
            }

            $mark('images saved');

            if ($request->has('variations')) {
                $rows = collect($request->variations)->map(fn ($v) => [
                    'ids'     => collect($v['attributes'] ?? [])->pluck('attribute_value_id')->filter()->values()->all(),
                    'sku'     => $v['sku'] ?? '',
                    'barcode' => $v['barcode'] ?? '',
                    'stock'   => $v['stock_quantity'] ?? 0,
                    'sell'    => $v['selling_price'] ?? null,
                    'compare' => $v['compare_at_price'] ?? null,
                ])->all();
                $this->createVariations($product, $rows, $autoSku);
            }

            $this->saveFabricSetup($product, $request);

            $mark('sizes saved');
            DB::commit();
            $mark('commit');

            $log = collect(DB::getQueryLog());
            Log::info('[Product Store] timing', [
                'total_ms'   => $marks['commit'],
                'steps_ms'   => $marks,   // cumulative: time since the save started
                'queries'    => $log->count(),
                'db_ms'      => (int) round($log->sum('time')),
                'slowest_ms' => (int) round($log->max('time') ?? 0),
                'sizes'      => count((array) $request->variations),
                'images'     => count((array) $request->file('prod_att')),
                'images_kb'  => (int) round(collect((array) $request->file('prod_att'))->sum(fn ($f) => $f->getSize()) / 1024),
            ]);
            DB::disableQueryLog();
            return redirect()->route('products.index')->with('success', 'Product ' . $product->sku . ' created successfully.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Product Store] Failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Product creation failed. Check logs for details.');
        }
    }

    public function show(Product $product)
    {
        return redirect()->route('products.index');
    }

    public function details(Request $request)
    {
        $product = Product::findOrFail($request->id);
        return response()->json([
            'id'               => $product->id,
            'name'             => $product->name,
            'code'             => $product->item_code ?? '',
            'unit'             => $product->unit ?? '',
            'cost_price'       => $product->cost_price ?? 0,
            'cmt_cost'         => $product->cmt_cost ?? 0,
            'brand'            => $product->brand ?? '',
            'weight'           => $product->weight ?? 0,
            'compare_at_price' => $product->compare_at_price ?? 0,
            'sku_opening_date' => $product->sku_opening_date ? $product->sku_opening_date->format('Y-m-d') : null,
        ]);
    }

    public function edit($id)
    {
        $product         = Product::with(['images', 'variations.attributeValues'])->findOrFail($id);
        $categories      = ProductCategory::all();
        $subcategories   = ProductSubcategory::all();
        $attributes      = Attribute::with('values')->get();
        $units           = MeasurementUnit::all();
        $vendors         = ChartOfAccounts::whereIn('account_type', ['customer', 'vendor'])->orderBy('name')->get();

        $attributeValues = collect();
        foreach ($attributes as $attribute) {
            foreach ($attribute->values as $val) {
                $val->attribute = $attribute;
                $attributeValues->push($val);
            }
        }

        $fabrics = Product::where('item_type', 'raw')->where('id', '!=', $product->id)->orderBy('name')->get(['id', 'name', 'sku']);

        // Fabric (raw): PANNA list, article lines, and where the fabric is now
        $pannaAttr  = \App\Services\FabricSetup::pannaAttribute();
        $fgArticles = Product::with('variations:id,product_id,sku')->where('item_type', 'fg')->where('id', '!=', $product->id)->orderBy('name')->get(['id', 'name', 'sku']);
        $selectedPannas = $product->variations->map(fn ($v) => \App\Services\FabricSetup::pannaValueOf($v))->filter()->values()->all();
        $fabricRows = \App\Models\FabricArticle::with('fabricVariation')->where('fabric_id', $product->id)->get()
            ->map(fn ($r) => [
                'panna_value_id'       => \App\Services\FabricSetup::pannaValueOf($r->fabricVariation),
                'article_id'           => $r->article_id,
                'article_variation_id' => $r->article_variation_id,
                'consumption'          => $r->consumption,
            ])->all();
        $fabricStock = $this->fabricStock($product);

        return view('products.edit', compact(
            'product', 'categories', 'subcategories', 'attributes', 'attributeValues', 'units', 'vendors', 'fabrics',
            'pannaAttr', 'fgArticles', 'selectedPannas', 'fabricRows', 'fabricStock'
        ));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name'             => 'required|string|max:255|unique:products,name,' . $id,
            'category_id'      => 'required|exists:product_categories,id',
            'subcategory_id'   => 'nullable|exists:product_subcategories,id',
            'sku'              => 'required|string|unique:products,sku,' . $id,
            'barcode'          => 'nullable|string|unique:products,barcode,' . $id,
            'measurement_unit' => 'required|exists:measurement_units,id',
            'item_type'        => 'required|in:fg,raw,service',
            'fabric_id'        => 'nullable|exists:products,id|not_in:' . $id,
            'consumption'      => 'nullable|numeric|min:0',
            'cmt_cost'         => 'nullable|numeric|min:0',
            'new_variations.*.barcode' => 'nullable|string|distinct|unique:product_variations,barcode',
            'variations.*.selling_price'        => 'nullable|numeric|min:0',
            'variations.*.compare_at_price'     => 'nullable|numeric|min:0',
            'new_variations.*.selling_price'    => 'nullable|numeric|min:0',
            'new_variations.*.compare_at_price' => 'nullable|numeric|min:0',
        ], [
            'new_variations.*.barcode.unique'   => 'Barcode :input is already used by another variation.',
            'new_variations.*.barcode.distinct' => 'The same barcode is typed on two new variations.',
        ]);

        DB::beginTransaction();
        try {
            $product = Product::findOrFail($id);

            $data = $request->only([
                'name', 'category_id', 'subcategory_id', 'vendor_id', 'brand',
                'sku', 'barcode', 'sku_opening_date', 'measurement_unit', 'item_type',
                'weight', 'cmt_cost', 'cost_price',
                'opening_stock', 'description', 'selling_price', 'compare_at_price',
                'consumption', 'fabric_id', 'reorder_level', 'max_stock_level', 'minimum_order_qty', 'is_active',
            ]);
            foreach (['cmt_cost', 'cost_price', 'selling_price'] as $num) {   // blank money box = 0
                if (array_key_exists($num, $data) && $data[$num] === null) $data[$num] = 0;
            }
            $product->update($data);

            $handledVariationIds = [];

            if (is_array($request->variations)) {
                foreach ($request->variations as $variationData) {
                    $variation = ProductVariation::findOrFail($variationData['id']);
                    // a barcode is independent of the SKU: changing the SKU never changes it.
                    // A size that has none yet (blank box) gets a random unique number.
                    $barcode = trim((string) ($variationData['barcode'] ?? ''));
                    if ($barcode === '') $barcode = $variation->barcode ?: \App\Services\BarcodeGenerator::generate();
                    $update = [
                        'sku'            => $variationData['sku'],
                        'barcode'        => $barcode,
                        'stock_quantity' => $variationData['stock_quantity'] ?? 0,
                    ];
                    // price fields are posted only for finished goods; blank = product's default
                    if (array_key_exists('selling_price', $variationData)) {
                        $update['selling_price'] = $this->priceOr($variationData['selling_price'], null);
                    }
                    if (array_key_exists('compare_at_price', $variationData)) {
                        $update['compare_at_price'] = $this->priceOr($variationData['compare_at_price'], null);
                    }
                    $variation->update($update);

                    if (!empty($variationData['attributes'])) {
                        $variation->attributeValues()->sync($variationData['attributes']);
                    }

                    $handledVariationIds[] = $variation->id;
                }
            }

            if (is_array($request->new_variations)) {
                $rows = collect($request->new_variations)->map(fn ($v) => [
                    'ids'     => collect((array) ($v['attributes'] ?? []))->filter()->values()->all(),
                    'sku'     => $v['sku'] ?? '',
                    'barcode' => $v['barcode'] ?? '',
                    'stock'   => $v['stock_quantity'] ?? 0,
                    'sell'    => $v['selling_price'] ?? null,
                    'compare' => $v['compare_at_price'] ?? null,
                ])->all();
                $handledVariationIds = array_merge($handledVariationIds, $this->createVariations($product, $rows, false));
            }

            if ($request->filled('removed_variations')) {
                ProductVariation::whereIn('id', $request->removed_variations)->delete();
            }

            if ($request->hasFile('prod_att')) {
                foreach ($request->file('prod_att') as $file) {
                    $path = $file->store('products', 'public');
                    $product->images()->create(['image_path' => $path]);
                }
            }

            if ($request->filled('removed_images')) {
                foreach ($request->removed_images as $imgId) {
                    $img = $product->images()->find($imgId);
                    if ($img) {
                        if (Storage::disk('public')->exists($img->image_path)) {
                            Storage::disk('public')->delete($img->image_path);
                        }
                        $img->delete();
                    }
                }
            }

            $this->saveFabricSetup($product->fresh(), $request);

            DB::commit();
            return redirect()->route('products.index')->with('success', 'Product updated successfully.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Product Update] Failed', ['error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Product update failed: ' . $e->getMessage());
        }
    }

    /** Fabric (raw) form section: PANNA variations + articles & consumption. */
    private function saveFabricSetup(Product $product, Request $request): void
    {
        if ($product->item_type !== 'raw' || !$request->has('fabric_setup_present')) {
            return;
        }
        \App\Services\FabricSetup::syncPannas($product, (array) $request->input('fabric_pannas', []));
        // consumption is now per product category × PANNA (Products → PANNA Consumption)
    }

    /** Fabric balance PANNA-wise × location (warehouse / CMT …) from the stock ledger. */
    private function fabricStock(Product $product): array
    {
        if ($product->item_type !== 'raw') return ['locations' => [], 'rows' => []];

        $rows = \App\Models\StockLedger::where('product_id', $product->id)
            ->groupBy('location_id', 'variation_id')
            ->selectRaw('location_id, variation_id, SUM(qty) AS qty')
            ->havingRaw('ROUND(SUM(qty), 3) <> 0')->get();

        $locations = \App\Models\Location::whereIn('id', $rows->pluck('location_id')->unique())->pluck('name', 'id')->all();
        $skus = $product->variations->pluck('sku', 'id');

        return [
            'locations' => $locations,
            'rows' => $rows->groupBy('variation_id')->map(fn ($g, $vid) => [
                'panna' => $vid ? ($skus[$vid] ?? '#' . $vid) : '(no PANNA)',
                'qty'   => $g->mapWithKeys(fn ($r) => [$r->location_id => (float) $r->qty])->all(),
            ])->values()->all(),
        ];
    }

    /** AJAX: preview the next SKU for a category (not reserved until save). */
    public function nextSku($categoryId)
    {
        return response()->json(['sku' => Product::previewSku((int) $categoryId)]);
    }

    /**
     * Save new sizes of a product in a handful of queries (instead of ~8 per size, which made
     * "Create product" slow on a remote database): attribute values, SKU clashes and barcode
     * clashes are each checked once for ALL sizes, and the attribute links are inserted in one go.
     *
     * Each $rows item: ids (attribute value ids), sku, barcode, stock, sell, compare.
     * SKU: typed one, else product SKU + attribute values (made unique with -2, -3 …).
     * Barcode: typed one, else a random unique 12-digit number (never a copy of the SKU).
     * Returns the new variation ids.
     */
    private function createVariations(Product $product, array $rows, bool $forceAutoSku = false): array
    {
        if (!$rows) return [];

        $values = \App\Models\AttributeValue::whereIn('id', collect($rows)->pluck('ids')->flatten()->unique()->values())->get()->keyBy('id');

        // wanted SKUs
        $wanted = [];
        foreach ($rows as $i => $r) {
            $sku = trim((string) $r['sku']);
            if ($forceAutoSku || $sku === '' || str_starts_with($sku, '-')) {
                $sku = Product::variationSkuFrom($product->sku, collect($r['ids'])->map(fn ($id) => $values[$id] ?? null)->filter());
            }
            $wanted[$i] = $sku;
        }

        // make them unique — one query for the whole batch; suffixes only when something clashes
        $inDb = ProductVariation::withTrashed()->whereIn('sku', array_unique($wanted))->pluck('sku')->flip();
        $used = [];
        $final = [];
        foreach ($wanted as $i => $sku) {
            $cand = $sku; $n = 2;
            if (isset($inDb[$cand]) || isset($used[$cand])) {
                do { $cand = $sku . '-' . $n++; }
                while (isset($used[$cand]) || ProductVariation::withTrashed()->where('sku', $cand)->exists());
            }
            $used[$cand] = true;
            $final[$i] = $cand;
        }

        // barcodes: typed ones kept, blanks get random unique numbers (one check for all)
        $blank = array_keys(array_filter($rows, fn ($r) => trim((string) $r['barcode']) === ''));
        $gen   = $blank ? \App\Services\BarcodeGenerator::generateMany(count($blank)) : [];
        $genAt = array_combine($blank, $gen) ?: [];

        $ids = []; $pivot = []; $now = now();
        foreach ($rows as $i => $r) {
            $variation = $product->variations()->create([
                'sku'              => $final[$i],
                'barcode'          => trim((string) $r['barcode']) !== '' ? trim((string) $r['barcode']) : $genAt[$i],
                'stock_quantity'   => $r['stock'] ?? 0,
                // blank = the product's default price
                'selling_price'    => $this->priceOr($r['sell'], $product->selling_price),
                'compare_at_price' => $this->priceOr($r['compare'], $product->compare_at_price),
            ]);
            $ids[] = $variation->id;
            foreach (array_unique($r['ids']) as $valueId) {
                $pivot[] = ['product_variation_id' => $variation->id, 'attribute_value_id' => $valueId, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($pivot) DB::table('product_variation_attribute_values')->insert($pivot);

        return $ids;
    }

    /** A typed price, or the fallback when the box was left blank. */
    private function priceOr($typed, $fallback): ?float
    {
        if ($typed !== null && $typed !== '' && is_numeric($typed)) return round((float) $typed, 2);
        return $fallback !== null && $fallback !== '' ? round((float) $fallback, 2) : null;
    }

    private function uniqueVariationSku(string $sku): string
    {
        $candidate = $sku;
        $n = 2;
        while (ProductVariation::withTrashed()->where('sku', $candidate)->exists()) {
            $candidate = $sku . '-' . $n++;
        }
        return $candidate;
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();
        return redirect()->route('products.index')->with('success', 'Product deleted successfully.');
    }

    public function getByBarcode($barcode)
    {
        try {
            $variation = ProductVariation::with('product')->where('barcode', $barcode)->first()
                ?? ProductVariation::with('product')->where('sku', $barcode)->first()
                ?? app(\App\Services\ItemCodeLookup::class)->loose(ProductVariation::with('product'), (string) $barcode)
                ?? \App\Services\ShortBarcode::variationFor((string) $barcode);   // short code printed on labels with a too-long barcode

            if ($variation && $variation->product) {
                return response()->json([
                    'success'   => true,
                    'type'      => 'variation',
                    'variation' => [
                        'id'               => $variation->id,
                        'product_id'       => $variation->product_id,
                        'sku'              => $variation->sku,
                        'barcode'          => $variation->barcode,
                        'name'             => $variation->product->name,
                        'brand'            => $variation->product->brand ?? '',
                        'cost_price'       => $variation->product->cost_price ?? 0,
                        'cmt_cost'         => $variation->product->cmt_cost ?? 0,
                        'selling_price'    => $variation->salePrice(),
                        'compare_at_price' => $variation->comparePrice() ?? 0,
                        'price'            => $variation->salePrice(),   // used by the sale forms when a code is scanned
                        'weight'           => $variation->product->weight ?? 0,
                    ],
                ]);
            }

            $product = Product::where('barcode', $barcode)->first()
                ?? Product::where('sku', $barcode)->first();

            if ($product) {
                return response()->json([
                    'success' => true,
                    'type'    => 'product',
                    'product' => [
                        'id'               => $product->id,
                        'name'             => $product->name,
                        'brand'            => $product->brand ?? '',
                        'barcode'          => $product->barcode,
                        'sku'              => $product->sku,
                        'cost_price'       => $product->cost_price ?? 0,
                        'cmt_cost'         => $product->cmt_cost ?? 0,
                        'selling_price'    => $product->selling_price ?? 0,
                        'compare_at_price' => $product->compare_at_price ?? 0,
                        'weight'           => $product->weight ?? 0,
                    ],
                ]);
            }

            return response()->json(['success' => false, 'message' => 'No product or variation found for this barcode']);

        } catch (\Exception $e) {
            Log::error('Barcode lookup failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error occurred while searching barcode']);
        }
    }

    public function getVariations($productId)
    {
        $product = Product::with('variations', 'measurementUnit')->find($productId);

        if (!$product) {
            return response()->json(['success' => false, 'variation' => []]);
        }

        $unitId     = $product->measurementUnit->id ?? null;
        $variations = $product->variations->map(fn($v) => [
            'id'               => $v->id,
            'sku'              => $v->sku,
            'barcode'          => $v->barcode,
            'unit'             => $unitId,
            'price'            => $v->setRelation('product', $product)->salePrice(),
            'selling_price'    => $v->salePrice(),
            'compare_at_price' => $v->comparePrice(),
        ])->toArray();

        return response()->json([
            'success'   => true,
            'variation' => $variations,
            'product'   => [
                'id'               => $product->id,
                'name'             => $product->name,
                'brand'            => $product->brand ?? '',
                'cmt_cost'         => $product->cmt_cost,
                'cost_price'       => $product->cost_price,
                'selling_price'    => $product->selling_price,
                'compare_at_price' => $product->compare_at_price,
                'weight'           => $product->weight,
                'unit'             => $unitId,
            ],
        ]);
    }

    public function getVariations2($productId)
    {
        $product = Product::with([
            'variations.attributeValues.attribute',
            'measurementUnit',
        ])->find($productId);

        if (!$product) {
            return response()->json(['success' => false, 'variation' => []]);
        }

        $unitId     = $product->measurementUnit->id ?? null;
        $variations = $product->variations->map(fn($v) => [
            'id'         => $v->id,
            'sku'        => $v->sku,
            'barcode'    => $v->barcode,
            'unit'       => $unitId,
            'price'            => $v->setRelation('product', $product)->salePrice(),
            'selling_price'    => $v->salePrice(),
            'compare_at_price' => $v->comparePrice(),
            'attributes' => $v->attributeValues->map(fn($av) => [
                'id'        => $av->id,
                'value'     => $av->value,
                'attribute' => ['id' => $av->attribute->id, 'name' => $av->attribute->name],
            ])->toArray(),
        ])->toArray();

        return response()->json([
            'success'   => true,
            'variation' => $variations,
            'product'   => [
                'id'               => $product->id,
                'name'             => $product->name,
                'brand'            => $product->brand ?? '',
                'cmt_cost'         => $product->cmt_cost,
                'cost_price'       => $product->cost_price,
                'selling_price'    => $product->selling_price,
                'compare_at_price' => $product->compare_at_price,
                'weight'           => $product->weight,
                'unit'             => $unitId,
            ],
        ]);
    }

    // ================================================================
    //  BULK EXPORT
    // ================================================================
    public function bulkExport()
    {
        $attributes = Attribute::pluck('name')->toArray();

        $columns = array_merge([
            'Product SKU',
            'Product Barcode',
            'Product Name',
            'Brand',
            'Category Code',
            'Subcategory ID',
            'Unit ID',
            'Item Type',
            'Description',
            'Vendor ID',
            'Weight',
            'SKU Opening Date',
            'CMT Cost',
            'Cost Price',
            'Selling Price',
            'Compare At Price',
            'Opening Stock',
            'Reorder Level',
            'Max Stock Level',
            'Min Order Qty',
            'Variation SKU',
            'Variation Barcode',
            'Variation Stock',
            'Variation Selling Price',
            'Variation Compare At Price',
        ], $attributes);

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=products_export.csv',
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $callback = function () use ($columns, $attributes) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

            fputcsv($file, $columns); // ← column headers row

            $products = Product::with(['category', 'variations.attributeValues.attribute'])->get();

            foreach ($products as $product) {
                $productRow = [
                    $product->sku,
                    $product->barcode ?? '',
                    $product->name,
                    $product->brand ?? '',
                    $product->category->code ?? '',
                    $product->subcategory_id ?? '',
                    $product->measurement_unit,
                    $product->item_type,
                    $product->description,
                    $product->vendor_id ?? '',
                    $product->weight ?? '',
                    $product->sku_opening_date ? $product->sku_opening_date->format('Y-m-d') : '',
                    $product->cmt_cost ?? 0,
                    $product->cost_price ?? 0,
                    $product->selling_price ?? 0,
                    $product->compare_at_price ?? '',
                    $product->opening_stock ?? 0,
                    $product->reorder_level ?? 0,
                    $product->max_stock_level ?? 0,
                    $product->minimum_order_qty ?? 0,
                ];

                if ($product->variations->isEmpty()) {
                    fputcsv($file, array_merge(
                        $productRow,
                        ['', '', 0, '', ''],
                        array_fill(0, count($attributes), '')
                    ));
                } else {
                    foreach ($product->variations as $variation) {
                        $variationRow = [
                            $variation->sku,
                            $variation->barcode ?? '',
                            $variation->stock_quantity ?? 0,
                            $variation->selling_price ?? '',
                            $variation->compare_at_price ?? '',
                        ];

                        $attrRow = [];
                        foreach ($attributes as $attr) {
                            $match     = $variation->attributeValues->first(
                                fn($av) => strtolower($av->attribute->name ?? '') === strtolower($attr)
                            );
                            $attrRow[] = $match ? $match->value : '';
                        }

                        fputcsv($file, array_merge($productRow, $variationRow, $attrRow));
                    }
                }
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ================================================================
    //  BULK IMPORT  (async — hands off to a queued job)
    // ================================================================
    public function bulkImport(Request $request)
    {
        $request->validate([
            'file'           => 'required|mimes:xlsx,csv,txt',
            'delete_missing' => 'nullable|boolean',
        ]);

        // Persist the upload so the queue worker (a separate process) can read it.
        $path = $request->file('file')->store('imports', 'local');

        $import = ProductImport::create([
            'original_name'  => $request->file('file')->getClientOriginalName(),
            'file_path'      => $path,
            'status'         => 'queued',
            'delete_missing' => $request->boolean('delete_missing'),
        ]);

        PrepareProductImportJob::dispatch($import->id)->onQueue('imports');

        return back()
            ->with('success', "Import queued (#{$import->id}). Processing in the background — progress will update below.")
            ->with('import_id', $import->id);
    }

    // ================================================================
    //  IMPORT STATUS  (polled by the progress bar on the index page)
    // ================================================================
    public function importStatus($id)
    {
        $import = ProductImport::findOrFail($id);

        $progress = 0;
        if ($import->batch_id) {
            $batch = Bus::findBatch($import->batch_id);
            if ($batch) {
                $progress = $batch->progress(); // 0–100
            }
        }

        // If the import already finished, force the bar to 100%.
        if (in_array($import->status, ['completed', 'failed'], true)) {
            $progress = 100;
        }

        // Nobody picked the job up yet → say so instead of a silent bar.
        $message = $import->message;
        if ($import->status === 'queued' && $import->created_at && $import->created_at->lt(now()->subMinutes(2))) {
            $message = 'Waiting for the background worker (queued ' . $import->created_at->diffForHumans()
                . '). The server task that runs "php artisan schedule:run" every minute is not working — '
                . 'or run once on the server: php artisan queue:work --queue=default,imports --stop-when-empty';
        }

        return response()->json([
            'id'       => $import->id,
            'status'   => $import->status,
            'progress' => $progress,
            'message'  => $message,
            'counts'   => [
                'products_created'   => $import->products_created,
                'products_updated'   => $import->products_updated,
                'products_failed'    => $import->products_failed,
                'variations_created' => $import->variations_created,
                'variations_updated' => $import->variations_updated,
                'variations_failed'  => $import->variations_failed,
            ],
        ]);
    }

    // ================================================================
    //  BULK UPLOAD TEMPLATE
    // ================================================================
    public function bulkUploadTemplate()
    {
        $attributes    = Attribute::orderBy('id')->pluck('name')->toArray();
        $categories    = ProductCategory::orderBy('code')->get(['id', 'code', 'name']);
        $subcategories = ProductSubcategory::orderBy('id')->get(['id', 'name']);
        $units         = MeasurementUnit::orderBy('id')->get(['id', 'name', 'shortcode']);

        $fixedCols = [
            'Product SKU',
            'Product Barcode',
            'Product Name',
            'Brand',
            'Category Code',
            'Subcategory ID',
            'Unit ID',
            'Item Type',
            'Description',
            'Vendor ID',
            'Weight',
            'SKU Opening Date',
            'CMT Cost',
            'Cost Price',
            'Selling Price',
            'Compare At Price',
            'Opening Stock',
            'Reorder Level',
            'Max Stock Level',
            'Min Order Qty',
            'Variation SKU',
            'Variation Barcode',
            'Variation Stock',
            'Variation Selling Price',
            'Variation Compare At Price',
        ];

        $columns = array_merge($fixedCols, $attributes);

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename=product_bulk_template.csv',
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $callback = function () use ($columns, $attributes, $categories, $subcategories, $units) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel

            fputcsv($file, $columns); // ← column headers row

            $catList  = 'category code: ' . $categories->map(fn($c) => $c->code . '=' . $c->name)->implode(' | ');
            $subList  = $subcategories->count()
                ? $subcategories->map(fn($s) => $s->id . '=' . $s->name)->implode(' | ')
                : 'optional — leave blank if none';
            $unitList = $units->map(fn($u) => $u->id . '=' . $u->shortcode)->implode(' | ');

            $helperRow = [
                '← your SKU',
                'barcode (blank = auto number)',
                '← product name',
                'optional brand name',
                $catList,
                $subList,
                $unitList,
                'fg | raw | service',
                'optional description',
                'leave blank',
                'weight (e.g. kg)',
                'YYYY-MM-DD (optional)',
                'CMT/making cost',
                'default purchase rate',
                'default selling price (all sizes)',
                'default compare-at / original price (optional)',
                'opening qty',
                'reorder qty',
                'max qty',
                'min order qty',
                '← variation SKU (blank if no variations)',
                'barcode (blank = auto number)',
                'variation stock qty',
                'size selling price (blank = product selling price)',
                'size compare-at price (blank = product compare-at)',
            ];

            foreach ($attributes as $attr) {
                $helperRow[] = '← ' . $attr . ' value';
            }

            fputcsv($file, $helperRow);

            $defaultCatId  = $categories->first()?->code ?? '';
            $defaultSubId  = '';
            $defaultUnitId = $units->first()?->id ?? 1;
            $blankAttrs    = array_fill(0, count($attributes), '');
            $attrIdx       = array_flip(array_map('strtolower', $attributes));

            $makeAttrRow = function (array $vals) use ($attributes, $attrIdx): array {
                $row = array_fill(0, count($attributes), '');
                foreach ($vals as $attrName => $val) {
                    $key = strtolower($attrName);
                    if (isset($attrIdx[$key])) $row[$attrIdx[$key]] = $val;
                }
                return $row;
            };

            // Example 1: FG with size + color
            $fgExamples = [
                ['sku' => 'JKT-BLK-S', 'color' => 'Black', 'size' => 'S', 'stock' => 10],
                ['sku' => 'JKT-BLK-M', 'color' => 'Black', 'size' => 'M', 'stock' => 15],
                ['sku' => 'JKT-BLK-L', 'color' => 'Black', 'size' => 'L', 'stock' => 12, 'price' => 5200],
                ['sku' => 'JKT-BRN-S', 'color' => 'Brown', 'size' => 'S', 'stock' => 8],
                ['sku' => 'JKT-BRN-M', 'color' => 'Brown', 'size' => 'M', 'stock' => 10],
            ];
            foreach ($fgExamples as $v) {
                fputcsv($file, array_merge([
                    'JKT-001', '', 'Classic Leather Jacket', 'Generic Leather Co',
                    $defaultCatId, $defaultSubId, $defaultUnitId,
                    'fg', 'Premium quality leather jacket', '',
                    '0.8', '2025-01-01',
                    '2500', '3000', '5000', '5500',
                    '0', '5', '100', '1',
                    $v['sku'], '', $v['stock'], $v['price'] ?? '', '',
                ], $makeAttrRow(['size' => $v['size'], 'color' => $v['color']])));
            }

            // Example 2: FG with engraving
            $engravingExamples = [
                ['sku' => 'WLT-BLK-NO',  'color' => 'Black', 'add engraving?' => 'No',  'stock' => 20],
                ['sku' => 'WLT-BLK-YES', 'color' => 'Black', 'add engraving?' => 'Yes', 'stock' => 10],
                ['sku' => 'WLT-BRN-NO',  'color' => 'Brown', 'add engraving?' => 'No',  'stock' => 18],
                ['sku' => 'WLT-BRN-YES', 'color' => 'Brown', 'add engraving?' => 'Yes', 'stock' => 8],
            ];
            foreach ($engravingExamples as $v) {
                fputcsv($file, array_merge([
                    'WLT-001', '', 'Leather Bifold Wallet', 'Generic Leather Co',
                    $defaultCatId, $defaultSubId, $defaultUnitId,
                    'fg', 'Genuine leather wallet with optional engraving', '',
                    '0.15', '2025-01-01',
                    '700', '900', '1800', '2000',
                    '0', '5', '50', '1',
                    $v['sku'], '', $v['stock'], $v['price'] ?? '', '',
                ], $makeAttrRow(['color' => $v['color'], 'add engraving?' => $v['add engraving?']])));
            }

            // Example 3: FG no variations
            fputcsv($file, array_merge([
                'BELT-001', '', 'Classic Leather Belt', 'Generic Leather Co',
                $defaultCatId, $defaultSubId, $defaultUnitId,
                'fg', 'Hand-stitched genuine leather belt', '',
                '0.3', '2025-01-01',
                '400', '500', '1200', '1400',
                '25', '5', '100', '1',
                '', '', '0', '', '',
            ], $blankAttrs));

            // Example 4: Raw material
            $rawUnit = $units->firstWhere('shortcode', 'sq.ft')?->id
                    ?? $units->skip(1)->first()?->id
                    ?? $defaultUnitId;

            fputcsv($file, array_merge([
                'RAW-LEA-001', '', 'Genuine Sheep Leather', '',
                $defaultCatId, $defaultSubId, $rawUnit,
                'raw', 'Raw sheep leather for jacket production', '',
                '', '',
                '0', '150', '0', '',
                '200', '20', '1000', '1',
                '', '', '0', '', '',
            ], $blankAttrs));

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}