<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cmt_bills', function (Blueprint $t) {
            $t->id();
            $t->string('bill_no')->unique();
            $t->unsignedBigInteger('vendor_id');
            $t->unsignedBigInteger('location_id')->nullable();   // warehouse the FG sits in
            $t->date('date_from');
            $t->date('date_to');
            $t->date('bill_date');
            $t->decimal('total_qty', 14, 3)->default(0);
            $t->decimal('cmt_amount', 14, 2)->default(0);
            $t->decimal('fabric_amount', 14, 2)->default(0);
            $t->text('remarks')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index('vendor_id');
        });

        Schema::create('cmt_bill_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cmt_bill_id')->constrained('cmt_bills')->cascadeOnDelete();
            $t->unsignedBigInteger('category_id')->nullable();
            $t->unsignedInteger('sku_count')->default(0);
            $t->decimal('qty', 14, 3)->default(0);
            $t->decimal('rate', 14, 2)->default(0);
            $t->decimal('amount', 14, 2)->default(0);
            $t->unsignedBigInteger('fabric_id')->nullable();
            $t->unsignedBigInteger('fabric_variation_id')->nullable();
            $t->decimal('meters_per_pc', 10, 3)->default(0);
            $t->decimal('fabric_qty', 14, 3)->default(0);
            $t->decimal('fabric_rate', 14, 4)->default(0);
            $t->decimal('fabric_amount', 14, 2)->default(0);
            $t->timestamps();
        });

        Schema::table('production_receiving_details', function (Blueprint $t) {
            $t->unsignedBigInteger('cmt_bill_id')->nullable()->index();
            $t->boolean('legacy_billed')->default(false);
        });

        // Every GRN that exists today was billed the old way — keep it out of the new CMT Bill.
        DB::table('production_receiving_details')->update(['legacy_billed' => true]);
    }

    public function down(): void
    {
        Schema::table('production_receiving_details', function (Blueprint $t) {
            $t->dropColumn(['cmt_bill_id', 'legacy_billed']);
        });
        Schema::dropIfExists('cmt_bill_lines');
        Schema::dropIfExists('cmt_bills');
    }
};
