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
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('chart_of_accounts');
            $table->date('return_date');
            $table->string('return_no')->nullable();
            $table->string('ref_no')->nullable();
            $table->string('bill_no')->nullable();
            $table->text('remarks')->nullable();
            $table->decimal('convance_charges', 12, 2)->default(0);
            $table->decimal('bill_discount', 12, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
