<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ما يرى التاجر من المندوب وما يرى المندوب من التاجر (docs/plan/95):
 *
 * - «إخفاء المندوب عن التاجر» صار خياراً للتاجر لا للمندوب، مُعلَّماً لكلّ التجّار: لا يرى التاجر اسم المندوب ولا
 *   هاتفه ولا موقعه حتى تُفتح له. وعمود couriers.hidden_from_merchants لم يعد يُقرأ.
 * - «يرى هاتف التاجر» للمندوب صار مطفأً لكلّ المناديب، ولمن يُضاف بعدهم، حتى يُعطاه مندوبٌ بعينه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->boolean('hides_courier')->default(true);
        });

        Schema::table('couriers', function (Blueprint $table) {
            $table->boolean('sees_merchant_phone')->default(false)->change();
        });

        DB::table('couriers')->update(['sees_merchant_phone' => false]);
    }

    public function down(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->boolean('sees_merchant_phone')->default(true)->change();
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('hides_courier');
        });
    }
};
