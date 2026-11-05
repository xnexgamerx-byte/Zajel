<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الرمز الذي طُبع باركوداً في آخر وصلٍ للشحنة (docs/plan/121): به تعرف «مراجعة مسح الوسيط» أيقرأ نظامهم ما على
 * الطرد — لا تخمينٌ من تاريخ الطباعة. وما طُبع قبله يبقى فارغاً، فتُقدَّر المراجعة من تاريخ طباعته.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // في آخر الجدول: يُضاف فوراً بلا إعادة بنائه (MySQL 8 INSTANT)
            $table->string('label_code', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('label_code');
        });
    }
};
