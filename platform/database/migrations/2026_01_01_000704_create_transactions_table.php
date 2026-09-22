<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفتر الحركات — كل ريال يتحرّك يترك صفاً هنا، ولا يُحذف صف أبداً.
 * أرصدة merchants.balance و couriers.cash_in_hand مشتقّة من هذا الجدول
 * ويجب أن تساويه دائماً (اختبار مطابقة دوري).
 *
 * الاتجاه من منظور الطرف:
 *   credit = له (الشركة مدينة له)
 *   debit  = عليه
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('account_type', 20);   // merchant | courier | company
            $table->unsignedBigInteger('account_id');

            $table->string('direction', 6);       // credit | debit
            $table->string('category', 40);       // cod_collected, delivery_fee, return_fee,
                                                  // commission, payout, deduction, adjustment, invoice
            $table->bigInteger('amount');         // موجب دائماً — الاتجاه في direction
            $table->bigInteger('balance_after')->nullable();

            $table->foreignId('shipment_id')->nullable();
            $table->string('reference_type', 40)->nullable();  // courier_settlement | merchant_settlement | invoice
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->string('description', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'account_type', 'account_id', 'id'], 'tx_account_idx');
            $table->index(['company_id', 'created_at'], 'tx_time_idx');
            $table->index(['company_id', 'shipment_id'], 'tx_shipment_idx');
            $table->index(['reference_type', 'reference_id'], 'tx_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
