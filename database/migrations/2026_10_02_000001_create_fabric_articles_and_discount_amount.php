<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fabric → article consumption (bill of material per panna) and
 * per-piece discount amount on sale lines. Re-runnable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fabric_articles')) {
            Schema::create('fabric_articles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('fabric_id');                       // raw product
                $table->unsignedBigInteger('fabric_variation_id')->nullable(); // panna / width (null = fabric has no panna)
                $table->unsignedBigInteger('article_id');                      // FG product
                $table->unsignedBigInteger('article_variation_id')->nullable();// size (null = all sizes)
                $table->decimal('consumption', 12, 3);                          // fabric per piece (unit of the fabric, e.g. meter)
                $table->timestamps();

                $table->unique(['fabric_id', 'fabric_variation_id', 'article_id', 'article_variation_id'], 'fabric_articles_unique');
                $table->index(['article_id', 'article_variation_id'], 'fabric_articles_article_idx');
            });
            try {
                Schema::table('fabric_articles', function (Blueprint $table) {
                    $table->foreign('fabric_id')->references('id')->on('products')->cascadeOnDelete();
                    $table->foreign('fabric_variation_id')->references('id')->on('product_variations')->cascadeOnDelete();
                    $table->foreign('article_id')->references('id')->on('products')->cascadeOnDelete();
                    $table->foreign('article_variation_id')->references('id')->on('product_variations')->cascadeOnDelete();
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[HJ migration] fabric_articles foreign keys skipped: ' . $e->getMessage());
            }
        }

        if (!Schema::hasColumn('production_receiving_details', 'fabric_variation_id')) {
            Schema::table('production_receiving_details', fn (Blueprint $t) => $t->unsignedBigInteger('fabric_variation_id')->nullable()->after('fabric_id'));
        }
        if (!Schema::hasColumn('sale_invoice_items', 'discount_amount')) {
            Schema::table('sale_invoice_items', fn (Blueprint $t) => $t->decimal('discount_amount', 12, 2)->default(0)->after('discount'));
        }

        \App\Support\HjSetup::run(); // adds the PANNA attribute
    }

    public function down(): void
    {
        Schema::dropIfExists('fabric_articles');
        if (Schema::hasColumn('production_receiving_details', 'fabric_variation_id')) {
            Schema::table('production_receiving_details', fn (Blueprint $t) => $t->dropColumn('fabric_variation_id'));
        }
        if (Schema::hasColumn('sale_invoice_items', 'discount_amount')) {
            Schema::table('sale_invoice_items', fn (Blueprint $t) => $t->dropColumn('discount_amount'));
        }
    }
};
