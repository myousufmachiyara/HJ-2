<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Attachments on FG receiving from the CMT (+ original names on purchase attachments). Safe to re-run. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('production_receiving_attachments')) {
            Schema::create('production_receiving_attachments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('production_receiving_id')->constrained('production_receivings')->cascadeOnDelete();
                $t->string('file_path');
                $t->string('original_name')->nullable();
                $t->string('file_type')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasColumn('purchase_invoice_attachments', 'original_name')) {
            Schema::table('purchase_invoice_attachments', function (Blueprint $t) {
                $t->string('original_name')->nullable()->after('file_path');
                $t->string('file_type')->nullable()->after('original_name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('production_receiving_attachments');
        if (Schema::hasColumn('purchase_invoice_attachments', 'original_name')) {
            Schema::table('purchase_invoice_attachments', fn (Blueprint $t) => $t->dropColumn(['original_name', 'file_type']));
        }
    }
};
