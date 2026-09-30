<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdc_cheques', function (Blueprint $table) {
            $table->id();
            $table->string('pdc_no')->unique();                  // internal ref PDC-00001
            $table->unsignedBigInteger('vendor_id');             // payee (COA vendor)
            $table->unsignedBigInteger('bank_account_id');       // our bank account (COA bank)
            $table->string('cheque_no', 50);
            $table->date('issue_date');                          // handed over to vendor
            $table->date('cheque_date');                         // date written on cheque (due date)
            $table->decimal('amount', 15, 2);
            // issued | presented | cleared | bounced | cancelled | replaced
            $table->string('status', 20)->default('issued');
            $table->date('status_date')->nullable();
            $table->unsignedBigInteger('replaced_by_id')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['bank_account_id', 'cheque_no']);
            $table->index(['status', 'cheque_date']);
            $table->foreign('vendor_id')->references('id')->on('chart_of_accounts');
            $table->foreign('bank_account_id')->references('id')->on('chart_of_accounts');
            $table->foreign('replaced_by_id')->references('id')->on('pdc_cheques')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        // Bills the cheque pays (purchase invoices / FG receivings), with the amount applied to each.
        Schema::create('pdc_cheque_bills', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pdc_cheque_id');
            $table->string('bill_type', 30);                     // purchase | receiving
            $table->unsignedBigInteger('bill_id');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index(['bill_type', 'bill_id']);
            $table->foreign('pdc_cheque_id')->references('id')->on('pdc_cheques')->cascadeOnDelete();
        });

        Schema::create('pdc_cheque_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pdc_cheque_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->date('date');
            $table->string('remarks')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->foreign('pdc_cheque_id')->references('id')->on('pdc_cheques')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdc_cheque_logs');
        Schema::dropIfExists('pdc_cheque_bills');
        Schema::dropIfExists('pdc_cheques');
    }
};
