<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopifyStore extends Model
{
    protected $fillable = [
        'shop_name',
        'shop_url',
        'oauth_state',
        'status',
        'encrypted_token',
        'encrypted_client_id',
        'encrypted_client_secret',
        'token_expires_at',
        'default_category_id',
        'default_measurement_unit',
    ];

    protected $hidden = [
        'encrypted_token',
        'encrypted_client_id',
        'encrypted_client_secret',
        'oauth_state',
    ];

    protected $casts = [
        'default_category_id'      => 'integer',
        'default_measurement_unit' => 'integer',
        'token_expires_at'         => 'datetime',
    ];

    // ─────────────────────────────────────────────
    //  Relationships
    // ─────────────────────────────────────────────

    public function logs(): HasMany
    {
        return $this->hasMany(ShopifySyncLog::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    // ─────────────────────────────────────────────
    //  Token helpers
    //  getAccessToken() returns null (never throws)
    //  so callers can do a simple null-check.
    // ─────────────────────────────────────────────

    public function setAccessToken(string $token, ?int $expiresIn = null): void
    {
        $this->encrypted_token  = Crypt::encryptString($token);
        $this->token_expires_at = $expiresIn ? now()->addSeconds($expiresIn) : null;
        $this->save();
    }

    public function getAccessToken(): ?string
    {
        return $this->decryptOrNull($this->encrypted_token);
    }

    public function setClientCredentials(string $clientId, string $clientSecret): void
    {
        $this->encrypted_client_id     = Crypt::encryptString($clientId);
        $this->encrypted_client_secret = Crypt::encryptString($clientSecret);
        $this->save();
    }

    public function getClientId(): ?string
    {
        return $this->decryptOrNull($this->encrypted_client_id);
    }

    public function getClientSecret(): ?string
    {
        return $this->decryptOrNull($this->encrypted_client_secret);
    }

    public function hasClientCredentials(): bool
    {
        return $this->getClientId() !== null && $this->getClientSecret() !== null;
    }

    /**
     * Ask Shopify for a fresh Admin API token using the client credentials
     * grant (works for Dev Dashboard apps installed on a store in the same
     * organization). Saves it and returns null on success, or an error
     * message string on failure.
     */
    public function requestClientCredentialsToken(): ?string
    {
        $clientId     = $this->getClientId();
        $clientSecret = $this->getClientSecret();

        if (!$clientId || !$clientSecret) {
            return 'Client ID / Secret are not saved for this store.';
        }

        try {
            $response = Http::timeout(20)
                ->asForm()
                ->acceptJson()
                ->post("https://{$this->shop_url}/admin/oauth/access_token", [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                ]);
        } catch (\Throwable $e) {
            Log::error("Shopify client-credentials request failed for {$this->shop_url}: {$e->getMessage()}");
            return 'Could not reach Shopify: ' . $e->getMessage();
        }

        $token = $response->json('access_token');

        if (!$response->successful() || !$token) {
            $detail = $response->json('error_description')
                ?? $response->json('error')
                ?? substr($response->body(), 0, 200);

            Log::warning("Shopify client-credentials rejected for {$this->shop_url} (HTTP {$response->status()}): {$detail}");

            // Translate Shopify's terse errors into something actionable.
            return match (true) {
                $response->status() === 404 =>
                    "Shopify says Not Found for {$this->shop_url}. Either this is not your store's real *.myshopify.com address "
                    . "(check Shopify Admin → Settings → Domains, or the store handle in admin.shopify.com/store/<handle>), "
                    . "or the app is not installed on this store yet (it must appear in Settings → Apps).",
                str_contains((string) $detail, 'shop_not_permitted') =>
                    "This app and store are in different Shopify organizations. Create the app from the store's own admin "
                    . "(Settings → Apps → Develop apps → Build apps in Dev Dashboard).",
                str_contains((string) $detail, 'invalid_client') || str_contains((string) $detail, 'application_cannot_be_found') =>
                    'Client ID or Client Secret is wrong. Copy both again from Dev Dashboard → your app → Settings.',
                default => "Shopify rejected the request (HTTP {$response->status()}): {$detail}",
            };
        }

        $this->setAccessToken($token, (int) ($response->json('expires_in') ?: 86399));

        Log::info("Shopify token obtained (client credentials) for {$this->shop_url}");

        return null;
    }

    /**
     * Returns a usable token, renewing it first when it is about to expire
     * (client-credentials tokens only live 24h). OAuth tokens have no expiry
     * and are returned as-is.
     */
    public function getValidAccessToken(): ?string
    {
        $expiresSoon = $this->token_expires_at && $this->token_expires_at->lte(now()->addMinutes(5));

        if ((!$this->getAccessToken() || $expiresSoon) && $this->hasClientCredentials()) {
            $this->requestClientCredentialsToken();
            $this->refresh();
        }

        return $this->getAccessToken();
    }

    /**
     * Run one Admin GraphQL call. Retries when Shopify says THROTTLED / 429,
     * returns the "data" part, and throws a readable message on any error.
     */
    public function graphql(string $query, array $variables = []): array
    {
        $token = $this->getValidAccessToken();
        if (!$token) {
            throw new \RuntimeException('No valid Shopify access token — reconnect the store in Shopify settings.');
        }

        $version = config('services.shopify.api_version', '2026-07');
        $url     = "https://{$this->shop_url}/admin/api/{$version}/graphql.json";

        for ($attempt = 1; ; $attempt++) {
            $response = Http::timeout(60)
                ->withHeaders(['X-Shopify-Access-Token' => $token])
                ->acceptJson()
                ->post($url, ['query' => $query, 'variables' => $variables ?: new \stdClass()]);

            $throttled = $response->status() === 429
                || collect($response->json('errors') ?? [])->contains(fn ($e) => ($e['extensions']['code'] ?? null) === 'THROTTLED');

            if ($throttled && $attempt < 6) {
                usleep(2_000_000 * $attempt);   // let Shopify's cost bucket refill
                continue;
            }

            if (in_array($response->status(), [401, 403], true)) {
                throw new \RuntimeException("Shopify refused access (HTTP {$response->status()}). The app needs the write_products scope — "
                    . 'add it in Dev Dashboard → your app → Versions, release the version, then use Sync Now/reconnect.');
            }
            if (!$response->successful()) {
                throw new \RuntimeException("Shopify API error (HTTP {$response->status()}): " . Str::limit($response->body(), 200));
            }

            $errors = $response->json('errors');
            if ($errors) {
                $msg = is_array($errors) ? collect($errors)->pluck('message')->filter()->implode('; ') : (string) $errors;
                if (stripos($msg, 'access denied') !== false || stripos($msg, 'write_products') !== false) {
                    $msg = 'The Shopify app is missing the write_products scope — add it in Dev Dashboard → your app → Versions and release. (' . $msg . ')';
                }
                throw new \RuntimeException(Str::limit($msg ?: 'Unknown Shopify error', 300));
            }

            return $response->json('data') ?? [];
        }
    }

    // ─────────────────────────────────────────────
    //  Convenience helpers
    // ─────────────────────────────────────────────

    public function isConnected(): bool
    {
        return $this->status === 'connected'
            && ($this->getAccessToken() !== null || $this->hasClientCredentials());
    }

    /**
     * A sync would otherwise fall back to a guessed category/unit id, which
     * throws a foreign-key error for every single product if that id
     * doesn't exist. Require the admin to set real defaults first.
     */
    public function hasImportDefaults(): bool
    {
        return $this->default_category_id !== null && $this->default_measurement_unit !== null;
    }

    private function decryptOrNull(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}