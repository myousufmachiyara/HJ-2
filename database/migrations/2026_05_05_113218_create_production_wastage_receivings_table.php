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
        Schema::create('production_wastage_receivings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('production_id');
            $table->unsignedBigInteger('vendor_id');
            $table->date('rec_date');
            $table->string('grn_no')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('production_id', 'pwr_production_id_fk')->references('id')->on('productions');
            $table->foreign('vendor_id', 'pwr_vendor_id_fk')->references('id')->on('chart_of_accounts');
            $table->foreign('received_by', 'pwr_received_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_wastage_receivings');
    }
};
