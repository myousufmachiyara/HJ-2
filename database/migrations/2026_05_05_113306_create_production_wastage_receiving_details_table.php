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
        Schema::create('production_wastage_receiving_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wastage_receiving_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->unsignedBigInteger('unit_id');
            $table->decimal('quantity', 12, 3);
            $table->enum('return_type', ['extra', 'wastage'])->default('extra');
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->foreign('wastage_receiving_id', 'pwd_wastage_recv_id_fk')->references('id')->on('production_wastage_receivings')->cascadeOnDelete();
            $table->foreign('product_id', 'pwd_product_id_fk')->references('id')->on('products');
            $table->foreign('variation_id', 'pwd_variation_id_fk')->references('id')->on('product_variations')->nullOnDelete();
            $table->foreign('unit_id', 'pwd_unit_id_fk')->references('id')->on('measurement_units');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('production_wastage_receiving_details');
    }
};
