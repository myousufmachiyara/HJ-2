<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

            return "Shopify rejected the Client ID / Secret (HTTP {$response->status()}): {$detail}";
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