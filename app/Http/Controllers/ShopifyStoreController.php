<?php

namespace App\Http\Controllers;

use App\Models\MeasurementUnit;
use App\Models\ProductCategory;
use App\Models\ShopifyStore;
use App\Models\ShopifySyncLog;
use App\Jobs\ProcessShopifyImport;
use App\Jobs\PushProductsToShopify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ShopifyStoreController extends Controller
{
    /**
     * Any *.myshopify.com host — this is what keeps shop_url from being
     * abused to make the server call an arbitrary host (SSRF) during the
     * OAuth authorize redirect and the token exchange.
     */
    private const SHOP_URL_PATTERN = '/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/i';

    public function index()
    {
        $stores     = ShopifyStore::orderBy('shop_name')->get();
        $categories = ProductCategory::orderBy('name')->get();
        $units      = MeasurementUnit::orderBy('name')->get();
        $newCount   = PushProductsToShopify::eligibleQuery()->count();   // products not yet on Shopify

        return view('shopify.settings', compact('stores', 'categories', 'units', 'newCount'));
    }

    // ─────────────────────────────────────────────
    //  Step 1 — User submits form
    //  1) Try direct connect (client credentials grant)
    //  2) If Shopify refuses, fall back to the OAuth
    //     approval redirect (see oauthCallback)
    // ─────────────────────────────────────────────
    public function store(Request $request)
    {
        $validated = $request->validate([
            'shop_name'                 => 'required|string|max:255',
            'shop_url'                  => 'required|string|max:255',
            'client_id'                 => 'required|string|max:255',
            'client_secret'             => 'required|string|max:255',
            'default_category_id'       => 'required|integer|exists:product_categories,id',
            'default_measurement_unit'  => 'required|integer|exists:measurement_units,id',
        ]);

        $shopUrl = $this->normalizeShopUrl($request->shop_url);

        if (!$shopUrl) {
            return back()->withInput()->with('error',
                'Shop URL must be your store\'s *.myshopify.com address, e.g. yourstore.myshopify.com.'
            );
        }

        // Check if already connected
        $existing = ShopifyStore::where('shop_url', $shopUrl)
            ->where('status', 'connected')
            ->first();

        if ($existing) {
            return back()->withInput()
                ->with('error', 'This store is already connected. Disconnect it first to reconnect with new credentials.');
        }

        // Save the store + its import defaults + the app credentials
        // (encrypted). The credentials are kept so the 24h client-credentials
        // token can be renewed automatically before every sync.
        $store = ShopifyStore::updateOrCreate(
            ['shop_url' => $shopUrl],
            [
                'shop_name'                => $validated['shop_name'],
                'status'                   => 'pending',
                'default_category_id'      => $validated['default_category_id'],
                'default_measurement_unit' => $validated['default_measurement_unit'],
            ]
        );
        $store->setClientCredentials(trim($request->client_id), trim($request->client_secret));

        // ── Method 1: direct connect (client credentials grant) ──────────
        // Works for Dev Dashboard apps installed on a store in the same
        // Shopify organization. No browser redirect, no session, no HMAC.
        $directError = $store->requestClientCredentialsToken();

        if ($directError === null) {
            $store->update(['status' => 'connected', 'oauth_state' => null]);
            Log::info("Connected via client credentials: {$store->shop_name}");

            $dispatchError = $this->dispatchImport($store);

            return redirect()->route('shopify.settings')
                ->with('success', "✓ {$store->shop_name} connected!" . ($dispatchError ? '' : ' Import queued — check Sync History for progress.'))
                ->with('error', $dispatchError);
        }

        // A wrong store address or missing install can't be fixed by the
        // OAuth page either — show the reason instead of redirecting.
        if (str_contains($directError, 'Not Found') || str_contains($directError, 'Client ID or Client Secret is wrong')) {
            return back()->withInput($request->except('client_secret'))->with('error', $directError);
        }

        Log::info("Direct connect failed for {$store->shop_name}, falling back to OAuth redirect: {$directError}");

        // Flashed so it's shown when Shopify sends the browser back to
        // BillTrix (App URL = shopify/settings) without completing OAuth.
        Session::flash('error', "Direct connect failed — {$directError}. Trying Shopify approval page instead.");

        // ── Method 2 (fallback): OAuth authorization-code redirect ───────
        $state = Str::random(40);
        $store->update(['oauth_state' => $state]);

        Session::put("shopify_oauth_{$state}", [
            'client_id'     => Crypt::encryptString(trim($request->client_id)),
            'client_secret' => Crypt::encryptString(trim($request->client_secret)),
            'store_id'      => $store->id,
        ]);

        $authUrl = "https://{$shopUrl}/admin/oauth/authorize?" . http_build_query([
            'client_id'    => trim($request->client_id),
            // read_product_listings removed: it's a sales-channel-only scope and
            // makes Shopify reject the install for a normal custom app.
            'scope'        => config('services.shopify.scopes', 'read_products,read_inventory'),
            'redirect_uri' => route('shopify.oauth.callback'),
            'state'        => $state,
        ]);

        Log::info("OAuth started for: {$store->shop_name}");

        return redirect($authUrl);
    }

    // ─────────────────────────────────────────────
    //  Step 2 — Shopify redirects back
    //  Pull credentials from session (one-time use)
    //  Exchange code → token → encrypt → save
    //  Destroy session data immediately after
    // ─────────────────────────────────────────────
    public function oauthCallback(Request $request)
    {
        $code  = $request->get('code');
        $shop  = (string) $request->get('shop');
        $state = (string) $request->get('state');
        $hmac  = (string) $request->get('hmac');

        // Pull credentials from session
        $sessionKey  = "shopify_oauth_{$state}";
        $sessionData = Session::get($sessionKey);

        if (!$sessionData) {
            Log::warning("OAuth callback: no session data for state={$state}");
            return redirect()->route('shopify.settings')
                ->with('error', 'OAuth session expired or invalid. Please try connecting again.');
        }

        // Decrypt from session
        try {
            $clientId     = Crypt::decryptString($sessionData['client_id']);
            $clientSecret = Crypt::decryptString($sessionData['client_secret']);
        } catch (\Exception $e) {
            Session::forget($sessionKey);
            return redirect()->route('shopify.settings')
                ->with('error', 'Session data corrupted. Please try again.');
        }

        // Find the pending store
        $reauth = !empty($sessionData['reauth']);
        $store = ShopifyStore::where('id', $sessionData['store_id'])
            ->where('oauth_state', $state)
            ->whereIn('status', $reauth ? ['pending', 'connected'] : ['pending'])
            ->first();

        if (!$store) {
            Session::forget($sessionKey);
            Log::warning("OAuth callback: no matching store for state={$state}");
            return redirect()->route('shopify.settings')
                ->with('error', 'Store not found or already connected. Please try again.');
        }

        // FIX (SSRF / domain-confusion): never trust the `shop` query param on
        // its own to decide which host the server talks to. It must both be a
        // well-formed *.myshopify.com host AND match the shop_url the admin
        // originally entered (and which is tied to this state token). Without
        // this, a crafted callback URL could point the token exchange (and the
        // client_secret in it) at an arbitrary host.
        $shopUrl = $this->normalizeShopUrl($shop);

        if (!$shopUrl || !hash_equals($store->shop_url, $shopUrl)) {
            Session::forget($sessionKey);
            if (!$reauth) $store->update(['status' => 'failed']);   // a failed re-authorize keeps the working connection
            Log::warning("OAuth callback: shop mismatch — expected {$store->shop_url}, got " . ($shop ?: '(empty)'));
            return redirect()->route('shopify.settings')
                ->with('error', 'Security check failed (shop mismatch). Please try again.');
        }

        // Verify HMAC (exclude both hmac and the legacy signature param)
        if (!$this->verifyHmac($request->except(['hmac', 'signature']), $clientSecret, $hmac)) {
            Session::forget($sessionKey);
            if (!$reauth) $store->update(['status' => 'failed']);   // a failed re-authorize keeps the working connection
            Log::warning("OAuth HMAC failed for shop={$shopUrl}");
            return redirect()->route('shopify.settings')
                ->with('error', 'Security check failed. Please try again.');
        }

        // Exchange code for access token — always against the store's own
        // validated shop_url, never the raw request value.
        try {
            $response = Http::timeout(15)
                ->post("https://{$store->shop_url}/admin/oauth/access_token", [
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                    'code'          => $code,
                ]);

            if (!$response->successful()) {
                throw new \Exception("HTTP {$response->status()}");
            }

            $accessToken = $response->json('access_token');

            if (!$accessToken) {
                throw new \Exception('Empty token in response.');
            }

        } catch (\Exception $e) {
            // Destroy credentials from session immediately even on failure
            Session::forget($sessionKey);
            if (!$reauth) $store->update(['status' => 'failed']);   // a failed re-authorize keeps the working connection
            Log::error("Token exchange failed for {$shopUrl}: " . $e->getMessage());
            return redirect()->route('shopify.settings')
                ->with('error', 'Could not get access token from Shopify: ' . $e->getMessage());
        }

        // Destroy credentials from session — no longer needed, ever
        Session::forget($sessionKey);

        // Save encrypted access token, clear oauth state
        $store->setAccessToken($accessToken);
        $store->update([
            'oauth_state' => null,
            'status'      => 'connected',
        ]);

        Log::info("OAuth complete for: {$store->shop_name}" . ($reauth ? ' (re-authorized)' : ''));

        if ($reauth) {
            $scopes = rescue(fn () => $store->fresh()->grantedScopes(), [], false);
            return redirect()->route('shopify.settings')->with('success',
                "✓ {$store->shop_name} re-authorized. Granted: " . (implode(', ', $scopes) ?: '—') . '. You can push products now.');
        }

        // Dispatched to the queue — see dispatchImport(). Runs asynchronously
        // so this HTTP response isn't blocked for the duration of the import.
        $dispatchError = $this->dispatchImport($store);

        if ($dispatchError) {
            return redirect()->route('shopify.settings')
                ->with('success', "✓ {$store->shop_name} connected.")
                ->with('error', $dispatchError);
        }

        return redirect()->route('shopify.settings')
            ->with('success', "✓ {$store->shop_name} connected! Import queued — check Sync History for progress.");
    }

    // ─────────────────────────────────────────────
    //  Update per-store import defaults (category / unit
    //  used for products that don't already exist locally).
    // ─────────────────────────────────────────────
    public function updateDefaults(Request $request, $id)
    {
        $store = ShopifyStore::findOrFail($id);

        $validated = $request->validate([
            'default_category_id'      => 'required|integer|exists:product_categories,id',
            'default_measurement_unit' => 'required|integer|exists:measurement_units,id',
        ]);

        $store->update($validated);

        return back()->with('success', "Import defaults updated for {$store->shop_name}.");
    }

    // ─────────────────────────────────────────────
    //  Disconnect
    // ─────────────────────────────────────────────
    public function destroy($id)
    {
        $store = ShopifyStore::findOrFail($id);
        $name  = $store->shop_name;
        $store->delete();
        return back()->with('success', "Store \"{$name}\" disconnected.");
    }

    // ─────────────────────────────────────────────
    //  Manual sync
    // ─────────────────────────────────────────────
    // ─────────────────────────────────────────────
    //  Push software → Shopify (new products only, as Draft)
    // ─────────────────────────────────────────────

    /** Renew the token now and show which permissions Shopify has granted. */
    public function checkAccess($id)
    {
        $store = ShopifyStore::findOrFail($id);
        if ($store->hasClientCredentials() && ($err = $store->refreshAccess())) {
            return back()->with('error', "{$store->shop_name}: {$err}");
        }
        try {
            $scopes = $store->fresh()->grantedScopes();
        } catch (\Throwable $e) {
            return back()->with('error', "{$store->shop_name}: {$e->getMessage()}");
        }
        $list = implode(', ', $scopes) ?: 'none';
        return in_array('write_products', $scopes, true)
            ? back()->with('success', "{$store->shop_name}: access OK — granted {$list}. You can push products now.")
            : back()->with('error', "{$store->shop_name}: granted only {$list}. write_products is missing — click Re-authorize and approve the new permissions on Shopify.");
    }

    /** Send the admin to Shopify's approval page again so new scopes (write_products) are approved. */
    public function reauthorize($id)
    {
        $store = ShopifyStore::findOrFail($id);
        if (!$store->hasClientCredentials()) {
            return back()->with('error', 'Client ID / Secret are not saved for this store — disconnect and connect it again.');
        }

        $state = Str::random(40);
        $store->update(['oauth_state' => $state]);
        Session::put("shopify_oauth_{$state}", [
            'client_id'     => Crypt::encryptString($store->getClientId()),
            'client_secret' => Crypt::encryptString($store->getClientSecret()),
            'store_id'      => $store->id,
            'reauth'        => true,   // already connected: only refresh permissions, no full import
        ]);

        return redirect("https://{$store->shop_url}/admin/oauth/authorize?" . http_build_query([
            'client_id'    => $store->getClientId(),
            'scope'        => config('services.shopify.scopes'),
            'redirect_uri' => route('shopify.oauth.callback'),
            'state'        => $state,
        ]));
    }

    /** Shopify Stores page: push every product that is not on Shopify yet. */
    public function pushNew($id)
    {
        $store = ShopifyStore::findOrFail($id);
        return $this->dispatchPush($store, null);
    }

    /** Products list: push the ticked products to the chosen store. */
    public function pushSelected(Request $request)
    {
        $request->validate([
            'store_id'      => 'required|exists:shopify_stores,id',
            'product_ids'   => 'required|array|min:1',
            'product_ids.*' => 'integer|exists:products,id',
        ], ['product_ids.required' => 'Tick at least one product to push.']);

        return $this->dispatchPush(ShopifyStore::findOrFail($request->store_id), array_map('intval', $request->product_ids));
    }

    private function dispatchPush(ShopifyStore $store, ?array $productIds)
    {
        if (!$store->isConnected()) {
            return back()->with('error', "{$store->shop_name} is not connected.");
        }

        $count = PushProductsToShopify::eligibleQuery($productIds)->count();
        if ($count === 0) {
            return back()->with('error', $productIds
                ? 'None of the selected products can be pushed — only active finished goods that are not on Shopify yet are sent.'
                : 'All products are already on Shopify — nothing new to push.');
        }

        ShopifySyncLog::where('shopify_store_id', $store->id)->where('direction', 'push')->where('status', 'processing')
            ->update(['status' => 'failed', 'error_message' => 'Interrupted by a new push.']);

        $log = ShopifySyncLog::create([
            'shopify_store_id' => $store->id,
            'status'           => 'pending',
            'direction'        => 'push',
        ]);
        PushProductsToShopify::dispatch($store, $log, $productIds);

        return redirect()->route('shopify.settings')->with('success',
            "Pushing {$count} product(s) to {$store->shop_name} as Draft — see Sync History below for progress.");
    }

    public function manualSync($id)
    {
        $store = ShopifyStore::findOrFail($id);

        if (!$store->isConnected()) {
            return back()->with('error', "{$store->shop_name} is not connected.");
        }

        $error = $this->dispatchImport($store);

        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success',
            "Sync queued for {$store->shop_name} — check Sync History for progress."
        );
    }

    // ─────────────────────────────────────────────
    //  Bulk import
    // ─────────────────────────────────────────────
    public function import(Request $request)
    {
        $request->validate([
            'store_ids'   => 'required|array|min:1',
            'store_ids.*' => 'exists:shopify_stores,id',
        ]);

        $queued   = [];
        $skipped  = [];

        foreach ($request->store_ids as $storeId) {
            $store = ShopifyStore::find($storeId);

            if (!$store || $store->status !== 'connected') {
                $skipped[] = $store?->shop_name ?? "ID {$storeId}";
                continue;
            }

            if ($this->dispatchImport($store)) {
                $skipped[] = $store->shop_name . ' (missing import defaults)';
                continue;
            }

            $queued[] = $store->shop_name;
        }

        $message = '';

        if ($queued) {
            $message .= 'Sync queued for: ' . implode(', ', $queued) . '.';
        }

        if ($skipped) {
            $message .= ' Skipped: ' . implode(', ', $skipped) . '.';
        }

        return back()->with($skipped && !$queued ? 'error' : 'success', trim($message));
    }

    // ─────────────────────────────────────────────
    //  Dispatch import to the queue
    //
    //  This method:
    //   1. Refuses to queue a sync that has no valid default
    //      category/unit set (would otherwise crash on an FK
    //      constraint for every single product).
    //   2. Marks any stuck "processing" logs as failed
    //   3. Creates a fresh pending log
    //   4. Dispatches the job to the queue
    //
    //  Returns an error message string, or null on success.
    // ─────────────────────────────────────────────
    private function dispatchImport(ShopifyStore $store): ?string
    {
        if (!$store->hasImportDefaults()) {
            return "{$store->shop_name}: set a default category and unit before syncing.";
        }

        // Interrupt any sync that got stuck in "processing"
        ShopifySyncLog::where('shopify_store_id', $store->id)
            ->where('direction', 'import')
            ->where('status', 'processing')
            ->update(['status' => 'failed', 'error_message' => 'Interrupted by new sync.']);

        $log = ShopifySyncLog::create([
            'shopify_store_id' => $store->id,
            'status'           => 'pending',
            'direction'        => 'import',
        ]);

        // Dispatch to the queue — job runs asynchronously
        ProcessShopifyImport::dispatch($store, $log);

        return null;
    }

    // ─────────────────────────────────────────────
    //  Strip protocol/whitespace/trailing slash and require a
    //  genuine *.myshopify.com host. Returns null when invalid.
    //  This is the single choke point everything else relies on
    //  to avoid sending requests to an attacker-controlled host.
    // ─────────────────────────────────────────────
    private function normalizeShopUrl(?string $raw): ?string
    {
        $shopUrl = strtolower(trim((string) $raw));
        $shopUrl = preg_replace('#^https?://#', '', $shopUrl);
        $shopUrl = rtrim($shopUrl, '/');

        // Strip a trailing path/query if someone pasted a full URL.
        $shopUrl = explode('/', $shopUrl)[0];

        return preg_match(self::SHOP_URL_PATTERN, $shopUrl) ? $shopUrl : null;
    }

    // ─────────────────────────────────────────────
    //  Verify Shopify HMAC
    // ─────────────────────────────────────────────
    private function verifyHmac(array $params, string $secret, string $hmac): bool
    {
        if ($hmac === '') {
            return false;
        }

        // Shopify signs the *raw* key=value pairs joined with '&' — NOT a
        // URL-encoded query string. http_build_query() re-encodes values such
        // as the base64 `host` param ('=' → '%3D'), which made the HMAC check
        // fail on real installs ("Security check failed").
        ksort($params);
        $message = collect($params)
            ->map(fn ($v, $k) => $k . '=' . (is_array($v) ? json_encode($v) : $v))
            ->implode('&');
        $computed = hash_hmac('sha256', $message, $secret);
        return hash_equals($computed, $hmac);
    }
}