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
        Schema::create('shopify_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopify_store_id')
                ->constrained('shopify_stores')
                ->cascadeOnDelete();
 
            $table->string('status')->default('pending'); // pending | processing | completed | failed
            $table->integer('total_products')->default(0);
            $table->integer('synced_products')->default(0);
            $table->integer('failed_products')->default(0);
            $table->text('error_message')->nullable();
 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_sync_logs');
    }
};
