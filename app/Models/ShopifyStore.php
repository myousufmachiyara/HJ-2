<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class ShopifyStore extends Model
{
    protected $fillable = [
        'shop_name',
        'shop_url',
        'oauth_state',
        'status',
        'encrypted_token',
        'default_category_id',
        'default_measurement_unit',
    ];

    protected $hidden = [
        'encrypted_token',
        'oauth_state',
    ];

    protected $casts = [
        'default_category_id'      => 'integer',
        'default_measurement_unit' => 'integer',
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
    //  Only encrypted_token is ever stored.
    //  getAccessToken() returns null (never throws)
    //  so callers can do a simple null-check.
    // ─────────────────────────────────────────────

    public function setAccessToken(string $token): void
    {
        $this->encrypted_token = Crypt::encryptString($token);
        $this->save();
    }

    public function getAccessToken(): ?string
    {
        if (!$this->encrypted_token) {
            return null;
        }

        try {
            return Crypt::decryptString($this->encrypted_token);
        } catch (\Exception) {
            return null;
        }
    }

    // ─────────────────────────────────────────────
    //  Convenience helpers
    // ─────────────────────────────────────────────

    public function isConnected(): bool
    {
        return $this->status === 'connected' && $this->getAccessToken() !== null;
    }
}