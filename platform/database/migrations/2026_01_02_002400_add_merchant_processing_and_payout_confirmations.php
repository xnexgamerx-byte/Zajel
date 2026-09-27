<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «إدخال طلبات العميل للمعالجة» في بطاقة التاجر: يقرّر بنفسه ما يُفعل بمحاولته
 * الفاشلة من بوابته. وتأكيد استلام الدفعات: التاجر يؤكّد ما دُفع له، ومندوب
 * الاستلام يؤكّد ربحه — ومنهما «عملاء لم يؤكّدوا دفعات» و«مندوبو استلام لم
 * يؤكّدوا دفعات».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->boolean('can_process')->default(false)->after('hold_for_review');
        });

        Schema::table('merchant_settlements', function (Blueprint $table) {
            $table->timestamp('merchant_confirmed_at')->nullable();
        });

        Schema::table('pickup_payouts', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('paid_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('pickup_payouts', fn (Blueprint $table) => $table->dropColumn('confirmed_at'));
        Schema::table('merchant_settlements', fn (Blueprint $table) => $table->dropColumn('merchant_confirmed_at'));
        Schema::table('merchants', fn (Blueprint $table) => $table->dropColumn('can_process'));
    }
};
