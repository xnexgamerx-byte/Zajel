<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلب المحاسبة بتفاصيل دفعه (docs/plan/44): رقم البطاقة أو المحفظة واسم صاحبها كما كتبها التاجر،
 * ومبلغ رصيده ساعةَ طلب — فيصل الشركةَ الطلبُ كاملاً لا «طلب دفع» وحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_requests', function (Blueprint $table) {
            $table->string('payout_details', 255)->nullable()->after('payout_method');
            $table->bigInteger('amount')->nullable()->after('payout_details');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_requests', fn (Blueprint $table) => $table->dropColumn(['payout_details', 'amount']));
    }
};
