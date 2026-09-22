<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تسوية المندوب: يسلّم النقد المحصَّل، تُخصم عمولته، ويُقفل الكشف.
 * بعد التأكيد (confirmed) لا يُعدَّل — أي تصحيح يكون بحركة معاكسة في transactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('courier_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);

            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();

            $table->unsignedInteger('shipments_count')->default(0);
            $table->bigInteger('cod_total')->default(0);          // المحصَّل من الزبائن
            $table->bigInteger('commission_total')->default(0);   // مستحق المندوب
            $table->bigInteger('deductions')->default(0);         // خصومات (تلف، سلف)
            $table->bigInteger('net_amount')->default(0);         // الواجب تسليمه للشركة

            $table->string('status', 20)->default('draft');       // draft, confirmed, paid
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('notes', 500)->nullable();

            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'courier_id', 'status'], 'cs_courier_idx');
            $table->index(['company_id', 'status', 'created_at'], 'cs_status_idx');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreign('courier_settlement_id')->references('id')
                ->on('courier_settlements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['courier_settlement_id']);
        });
        Schema::dropIfExists('courier_settlements');
    }
};
