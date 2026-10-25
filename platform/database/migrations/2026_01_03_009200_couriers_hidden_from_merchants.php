<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «إخفاء المندوب عن العميل» (docs/plan/81): مندوبٌ لا يرى التاجر شيئاً عنه — لا اسمه ولا هاتفه ولا
 * موقعه على الخريطة — في تطبيقه وبوّابته.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->boolean('hidden_from_merchants')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->dropColumn('hidden_from_merchants');
        });
    }
};
