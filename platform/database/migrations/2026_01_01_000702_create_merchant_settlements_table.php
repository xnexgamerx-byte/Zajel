<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * كشف حساب التاجر ودفعته: مجموع المحصَّل ناقص الأجور ناقص أجور الراجع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);

            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();

            $table->unsignedInteger('shipments_count')->default(0);
            $table->unsignedInteger('returned_count')->default(0);
            $table->bigInteger('cod_total')->default(0);
            $table->bigInteger('delivery_fees_total')->default(0);
            $table->bigInteger('return_fees_total')->default(0);
            $table->bigInteger('cod_fees_total')->default(0);
            $table->bigInteger('deductions')->default(0);
            $table->bigInteger('net_amount')->default(0);          // الواجب دفعه للتاجر

            $table->string('status', 20)->default('draft');        // draft, confirmed, paid
            $table->enum('payout_method', ['cash', 'zaincash', 'asiahawala', 'fastpay', 'qi', 'fib', 'bank_transfer'])
                ->nullable();
            $table->string('payout_reference', 120)->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable();
            $table->string('notes', 500)->nullable();

            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'merchant_id', 'status'], 'ms_merchant_idx');
            $table->index(['company_id', 'status', 'created_at'], 'ms_status_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreign('merchant_settlement_id')->references('id')
                ->on('merchant_settlements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['merchant_settlement_id']);
        });
        Schema::dropIfExists('merchant_settlements');
    }
};
