<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_imports', function (Blueprint $table) {
            $table->id();
            $table->string('original_name')->nullable();
            $table->string('file_path');                 // stored on 'local' disk
            $table->string('batch_id')->nullable();      // job_batches id
            $table->string('status')->default('queued'); // queued|preparing|processing|completed|failed
            $table->boolean('delete_missing')->default(false);

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('products_created')->default(0);
            $table->unsignedInteger('products_updated')->default(0);
            $table->unsignedInteger('products_failed')->default(0);
            $table->unsignedInteger('variations_created')->default(0);
            $table->unsignedInteger('variations_updated')->default(0);
            $table->unsignedInteger('variations_failed')->default(0);

            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_imports');
    }
};