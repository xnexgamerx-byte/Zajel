<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «حجم الطلب» (docs/plan/28): يختاره التاجر مع طلبه — عادي أو كبير — فيُطبع على
 * الوصل ويراه المندوب قبل أن يخرج إليه. لا يغيّر الأجرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('size', 10)->default('normal')->after('pieces_count');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('size');
        });
    }
};
