<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAAM weekly sale settlement (separate from normal Sale Invoices).
 * One header per uploaded LAAM "Ledgers" export, one line per Item Id row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('laam_invoices')) {
            Schema::create('laam_invoices', function (Blueprint $t) {
                $t->id();
                $t->string('invoice_no')->unique();
                $t->date('date');
                $t->date('period_from')->nullable();
                $t->date('period_to')->nullable();
                $t->unsignedBigInteger('location_id');          // LAAM stock location
                $t->unsignedBigInteger('account_id');           // LAAM receivable account
                $t->string('ref_no')->nullable();
                $t->string('file_name')->nullable();
                $t->text('remarks')->nullable();
                $t->decimal('total_retail', 14, 2)->default(0);
                $t->decimal('total_realized', 14, 2)->default(0);
                $t->decimal('total_fee', 14, 2)->default(0);
                $t->decimal('total_pra_tax', 14, 2)->default(0);
                $t->decimal('total_sales_tax', 14, 2)->default(0);
                $t->decimal('total_income_tax', 14, 2)->default(0);
                $t->decimal('total_adjustment', 14, 2)->default(0); // seller discounts / coupons / rounding
                $t->decimal('total_payable', 14, 2)->default(0);    // what LAAM owes us (Seller Payable)
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index('location_id');
            });
        }

        if (!Schema::hasTable('laam_invoice_items')) {
            Schema::create('laam_invoice_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('laam_invoice_id');
                $t->unsignedBigInteger('product_id');
                $t->unsignedBigInteger('variation_id')->nullable();
                $t->string('item_name')->nullable();
                $t->string('sku')->nullable();
                $t->string('platform')->nullable();
                $t->string('brand')->nullable();
                $t->string('order_number')->nullable();
                $t->string('shopify_order_number')->nullable();
                $t->date('order_date')->nullable();
                $t->string('destination')->nullable();
                $t->string('payment_method')->nullable();
                $t->string('item_status')->nullable();
                $t->decimal('quantity', 12, 3)->default(1);
                $t->decimal('actual_price', 12, 2)->default(0);
                $t->decimal('retail_price', 12, 2)->default(0);
                $t->decimal('realized_sales', 12, 2)->default(0);
                $t->string('coupon_code')->nullable();
                $t->decimal('platform_fee_pct', 6, 2)->default(0);
                $t->decimal('seller_discount', 12, 2)->default(0);
                $t->decimal('net_platform_fee', 12, 2)->default(0);
                $t->decimal('pra_tax', 12, 2)->default(0);
                $t->decimal('sales_tax', 12, 2)->default(0);
                $t->decimal('income_tax', 12, 2)->default(0);
                $t->decimal('seller_payable', 12, 2)->default(0);
                $t->string('item_id')->unique();               // LAAM "Item Id" — stops double upload
                $t->date('realization_date')->nullable();
                $t->string('notes')->nullable();
                $t->decimal('unit_cost', 15, 4)->default(0);   // frozen avg cost (for COGS)
                $t->timestamps();
                $t->index('laam_invoice_id');
            });
        }

        // new system accounts (platform fee, PRA / sales / income tax) + laam_invoices.* permissions
        \App\Support\HjSetup::run();
    }

    public function down(): void
    {
        Schema::dropIfExists('laam_invoice_items');
        Schema::dropIfExists('laam_invoices');
    }
};
