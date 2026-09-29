<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dev Dashboard apps (the only kind Shopify lets you create since Jan 2026)
 * can get an Admin API token directly with the "client credentials" grant —
 * no browser redirect needed. Those tokens expire after 24h, so the app's
 * client id/secret are stored (encrypted) to renew the token automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_stores', function (Blueprint $table) {
            if (!Schema::hasColumn('shopify_stores', 'encrypted_client_id')) {
                $table->text('encrypted_client_id')->nullable()->after('encrypted_token');
            }
            if (!Schema::hasColumn('shopify_stores', 'encrypted_client_secret')) {
                $table->text('encrypted_client_secret')->nullable()->after('encrypted_client_id');
            }
            if (!Schema::hasColumn('shopify_stores', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('encrypted_client_secret');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shopify_stores', function (Blueprint $table) {
            $table->dropColumn(['encrypted_client_id', 'encrypted_client_secret', 'token_expires_at']);
        });
    }
};