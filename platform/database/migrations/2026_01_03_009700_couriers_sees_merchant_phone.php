<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «يرى هاتف التاجر» (docs/plan/92): مندوبٌ لا تعطيه الشركة هاتف التاجر لا يراه في تطبيقه — لا في الشحنة
 * ولا في طلب الاستلام. ومن قبلُ يراه كلّ مندوب، فيبقى كذلك حتى يُطفأ لمندوبٍ بعينه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->boolean('sees_merchant_phone')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->dropColumn('sees_merchant_phone');
        });
    }
};
