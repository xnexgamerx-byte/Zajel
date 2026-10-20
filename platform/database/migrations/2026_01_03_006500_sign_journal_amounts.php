<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المدين والدائن موجبان دائماً، لكن كل رصيدٍ يُحسب بطرحهما (debit - credit). وعلى MySQL
 * طرح عمودين بلا إشارة ناتجه سالب خطأٌ (1690 BIGINT UNSIGNED out of range) لا رقمٌ سالب —
 * فكان بناء الدفتر يقف عند أوّل شحنةٍ دائنة، وكل رصيدٍ دائنٍ يُسقط شاشته. وSQLite لا يفرّق،
 * فمرّت الاختبارات هناك. صارت الأعمدة بإشارة؛ والقيم كما هي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->bigInteger('debit')->default(0)->change();
            $table->bigInteger('credit')->default(0)->change();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->bigInteger('amount')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('debit')->default(0)->change();
            $table->unsignedBigInteger('credit')->default(0)->change();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('amount')->default(0)->change();
        });
    }
};
