<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أجرة المندوب تُكتب على الشحنة بيدٍ صاحبة صلاحية (docs/plan/38).
 *
 * تُحسب عند التسليم أو الرجوع من تسعيرة المندوب؛ فإن كتبها صاحب الشركة لشحنةٍ قبل
 * ذلك (منطقةٌ بعيدة مثلاً) لم يُمحَ ما كتب عند التسليم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->boolean('courier_commission_fixed')->default(false)->after('courier_commission');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', fn (Blueprint $table) => $table->dropColumn('courier_commission_fixed'));
    }
};
