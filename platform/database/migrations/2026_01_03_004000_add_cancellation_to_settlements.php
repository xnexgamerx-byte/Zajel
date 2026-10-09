<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حذف كشفٍ أو حركةٍ ماليّة في يومها وحده (docs/plan/38).
 *
 * الكشف المُقفَل لا يُمحى: يُلغى بحركاتٍ معاكسة لكل ما قيّده في الدفتر والصندوق،
 * ويبقى صفّه «ملغى» بسببه ومن ألغاه، وتعود شحناته لكشفٍ جديد. وبعد أربعٍ وعشرين
 * ساعةً من إقفاله لا يُلغى ولا يُعدَّل.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['courier_settlements', 'merchant_settlements', 'merchant_advances'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('cancelled_at')->nullable();
                $t->foreignId('cancelled_by_user_id')->nullable();
                $t->string('cancel_reason', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['courier_settlements', 'merchant_settlements', 'merchant_advances'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['cancelled_at', 'cancelled_by_user_id', 'cancel_reason']));
        }
    }
};
