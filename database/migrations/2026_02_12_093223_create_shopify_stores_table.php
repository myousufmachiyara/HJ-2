<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shopify_stores', function (Blueprint $table) {
            $table->id();
            $table->string('shop_name');
            $table->string('shop_url')->unique();
 
            // Only encrypted_token is used — no plaintext access_token column
            $table->text('encrypted_token')->nullable();
 
            $table->string('oauth_state', 64)->nullable();
            $table->string('status')->default('pending'); // pending | connected | failed
 
            // Per-store import defaults used by ProcessShopifyImport.
            // Falls back to 1 in the job when null.
            $table->unsignedBigInteger('default_category_id')->nullable();
            $table->unsignedInteger('default_measurement_unit')->nullable();
 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_stores');
    }
};
