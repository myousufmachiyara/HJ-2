<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which product brands belong to which Shopify store (e.g. hassanjee.com: Hassan Jee, Hassan Jee Men, AK By Hassan Jee). */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('shopify_stores', 'brands')) {
            Schema::table('shopify_stores', fn (Blueprint $t) => $t->text('brands')->nullable()->after('default_measurement_unit'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shopify_stores', 'brands')) {
            Schema::table('shopify_stores', fn (Blueprint $t) => $t->dropColumn('brands'));
        }
    }
};
