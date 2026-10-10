<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sync history now also records pushes (software → Shopify). Safe to re-run. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('shopify_sync_logs', 'direction')) {
            Schema::table('shopify_sync_logs', fn (Blueprint $t) => $t->string('direction', 10)->default('import')->after('status'));
        }
        if (!Schema::hasColumn('shopify_sync_logs', 'skipped_products')) {
            Schema::table('shopify_sync_logs', fn (Blueprint $t) => $t->integer('skipped_products')->default(0)->after('failed_products'));
        }
    }

    public function down(): void
    {
        foreach (['direction', 'skipped_products'] as $col) {
            if (Schema::hasColumn('shopify_sync_logs', $col)) {
                Schema::table('shopify_sync_logs', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
