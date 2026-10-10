<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صورة التاجر أو شعار متجره (docs/plan/59): يرفعها من تطبيقه، وتظهر في رأس رئيسيته.
 * تُحفظ على قرص النظام الخاصّ (لا العامّ) وتُفتح برمز التاجر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('logo_path', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
