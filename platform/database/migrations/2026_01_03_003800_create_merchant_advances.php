<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سلف التجّار (docs/plan/38).
 *
 * الشركة تعطي التاجر مالاً من صندوقٍ سلفةً، وتستردّه من مستحقّاته: كل كشفٍ يُقفَل له
 * يُخصم منه ما بقي من سلفه، الأقدم أوّلاً، حتى تُسدَّد — أو يسدّدها نقداً.
 *
 * السلفة قيدٌ عليه في دفتره (advance) فينقص رصيده بها، والاسترداد من الكشف لا قيد له:
 * الكشف يُدفع ناقصاً بقدر ما اقتُطع (advance_deduction)، فيعود الرصيد بمستحقّه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable();
            $table->foreignId('merchant_id');
            $table->string('number', 30);

            $table->bigInteger('amount');
            $table->bigInteger('recovered')->default(0);
            $table->string('status', 12)->default('open');   // open | repaid

            $table->foreignId('cash_box_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamp('repaid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'merchant_id', 'status'], 'ma_merchant_idx');
        });

        Schema::create('merchant_advance_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_advance_id');
            $table->string('kind', 12);                       // settlement | cash
            $table->foreignId('merchant_settlement_id')->nullable();
            $table->foreignId('cash_box_id')->nullable();
            $table->bigInteger('amount');
            $table->foreignId('created_by_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'merchant_advance_id'], 'mar_advance_idx');
            $table->index(['company_id', 'merchant_settlement_id'], 'mar_settlement_idx');
        });

        Schema::table('merchant_settlements', function (Blueprint $table) {
            // ما اقتُطع من الكشف لسلف التاجر: صافيه = مجموع سطوره ناقصاً هذا
            $table->bigInteger('advance_deduction')->default(0)->after('net_amount');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_settlements', fn (Blueprint $table) => $table->dropColumn('advance_deduction'));
        Schema::dropIfExists('merchant_advance_recoveries');
        Schema::dropIfExists('merchant_advances');
    }
};
