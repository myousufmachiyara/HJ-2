<?php

namespace App\Http\Controllers;

use App\Models\ShopifyStore;
use App\Models\ShopifySyncLog;
use App\Jobs\ProcessShopifyImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ShopifyStoreController extends Controller
{
    public function index()
    {
        $stores = ShopifyStore::all();
        return view('shopify.settings', compact('stores'));
    }

    // ─────────────────────────────────────────────
    //  Step 1 — User submits form
    //  Keep client_id + secret only in SESSION
    //  (encrypted, cleared after callback)
    // ─────────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'shop_name'     => 'required|string|max:255',
            'shop_url'      => 'required|string',
            'client_id'     => 'required|string',
            'client_secret' => 'required|string',
        ]);

        $shopUrl = rtrim(
            str_replace(['https://', 'http://'], '', $request->shop_url),
            '/'
        );

        // Check if already connected
        $existing = ShopifyStore::where('shop_url', $shopUrl)
            ->where('status', 'connected')
            ->first();

        if ($existing) {
            return back()->withInput()
                ->with('error', 'This store is already connected.');
        }

        // Generate CSRF state token
        $state = Str::random(40);

        // Save store with NO credentials — just name, url, state
        $store = ShopifyStore::updateOrCreate(
            ['shop_url' => $shopUrl],
            [
                'shop_name'   => $request->shop_name,
                'oauth_state' => $state,
                'status'      => 'pending',
            ]
        );

        // Store client_id + secret ONLY in session, encrypted.
        // They are used once in the callback then immediately destroyed.
        Session::put("shopify_oauth_{$state}", [
            'client_id'     => Crypt::encryptString($request->client_id),
            'client_secret' => Crypt::encryptString($request->client_secret),
            'store_id'      => $store->id,
        ]);

        // Redirect to Shopify OAuth
        $scopes      = 'read_products,read_inventory,read_product_listings';
        $redirectUri = route('shopify.oauth.callback');

        $authUrl = "https://{$shopUrl}/admin/oauth/authorize"
            . "?client_id={$request->client_id}"
            . "&scope={$scopes}"
            . "&redirect_uri=" . urlencode($redirectUri)
            . "&state={$state}";

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
        $shop  = $request->get('shop');
        $state = $request->get('state');
        $hmac  = $request->get('hmac');

        $shopUrl = rtrim(str_replace(['https://', 'http://'], '', $shop), '/');

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
        $store = ShopifyStore::where('id', $sessionData['store_id'])
            ->where('oauth_state', $state)
            ->where('status', 'pending')
            ->first();

        if (!$store) {
            Session::forget($sessionKey);
            Log::warning("OAuth callback: no matching store for state={$state}");
            return redirect()->route('shopify.settings')
                ->with('error', 'Store not found or already connected. Please try again.');
        }

        // Verify HMAC
        if (!$this->verifyHmac($request->except('hmac'), $clientSecret, $hmac)) {
            Session::forget($sessionKey);
            $store->update(['status' => 'failed']);
            Log::warning("OAuth HMAC failed for shop={$shopUrl}");
            return redirect()->route('shopify.settings')
                ->with('error', 'Security check failed. Please try again.');
        }

        // Exchange code for access token
        try {
            $response = Http::timeout(15)
                ->post("https://{$shop}/admin/oauth/access_token", [
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
            $store->update(['status' => 'failed']);
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

        Log::info("OAuth complete for: {$store->shop_name}");

        // FIX: dispatch to the queue instead of running synchronously.
        // The old runImport() called ->handle() inline which would block the
        // HTTP response for the full duration of the import (potentially minutes).
        $this->dispatchImport($store);

        return redirect()->route('shopify.settings')
            ->with('success', "✓ {$store->shop_name} connected! Import queued — check Sync History for progress.");
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
    //  FIX: dispatches to queue and returns immediately.
    //  Response no longer reports counts (not available
    //  yet) — user checks Sync History for results.
    // ─────────────────────────────────────────────
    public function manualSync($id)
    {
        $store = ShopifyStore::findOrFail($id);

        if ($store->status !== 'connected' || !$store->getAccessToken()) {
            return back()->with('error', "{$store->shop_name} is not connected.");
        }

        $this->dispatchImport($store);

        return back()->with('success',
            "Sync queued for {$store->shop_name} — check Sync History for progress."
        );
    }

    // ─────────────────────────────────────────────
    //  Bulk import
    //  FIX: dispatches each store to the queue;
    //  counts are no longer reported synchronously.
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

            $this->dispatchImport($store);
            $queued[] = $store->shop_name;
        }

        $message = '';

        if ($queued) {
            $message .= 'Sync queued for: ' . implode(', ', $queued) . '.';
        }

        if ($skipped) {
            $message .= ' Skipped (not connected): ' . implode(', ', $skipped) . '.';
        }

        return back()->with($skipped && !$queued ? 'error' : 'success', trim($message));
    }

    // ─────────────────────────────────────────────
    //  Dispatch import to the queue
    //  FIX: replaces the old runImport() which called
    //  ->handle() inline (blocking the HTTP request).
    //
    //  This method:
    //   1. Marks any stuck "processing" logs as failed
    //   2. Creates a fresh pending log
    //   3. Dispatches the job to the queue
    // ─────────────────────────────────────────────
    private function dispatchImport(ShopifyStore $store): ShopifySyncLog
    {
        // Interrupt any sync that got stuck in "processing"
        ShopifySyncLog::where('shopify_store_id', $store->id)
            ->where('status', 'processing')
            ->update(['status' => 'failed', 'error_message' => 'Interrupted by new sync.']);

        $log = ShopifySyncLog::create([
            'shopify_store_id' => $store->id,
            'status'           => 'pending',
        ]);

        // Dispatch to the queue — job runs asynchronously
        ProcessShopifyImport::dispatch($store, $log);

        return $log;
    }

    // ─────────────────────────────────────────────
    //  Verify Shopify HMAC
    // ─────────────────────────────────────────────
    private function verifyHmac(array $params, string $secret, string $hmac): bool
    {
        ksort($params);
        $computed = hash_hmac('sha256', http_build_query($params), $secret);
        return hash_equals($computed, $hmac);
    }
}