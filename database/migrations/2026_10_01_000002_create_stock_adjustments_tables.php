<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('adj_no')->unique();
            $table->date('date');
            $table->unsignedBigInteger('location_id');
            // opening    : opening stock (Dr location stock / Cr Opening Stock Equity)
            // count      : physical count — system qty is set to the counted qty
            // adjustment : manual +/− (damage, found, lost...)
            $table->string('type', 20)->default('opening');
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_adjustment_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->decimal('system_qty', 15, 3)->default(0);   // qty in software before (count type)
            $table->decimal('counted_qty', 15, 3)->nullable();  // physically counted (count type)
            $table->decimal('quantity', 15, 3);                 // signed difference posted (+ in, − out)
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->foreign('stock_adjustment_id')->references('id')->on('stock_adjustments')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('variation_id')->references('id')->on('product_variations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
