<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «شعار الوصل المختصر» (docs/plan/94): حرف الشعار وحده، كبيراً في أعلى الوصل. تختاره كلّ شركةٍ صورةً من
 * «بيانات الشركة»؛ وما لم تُرفع يُطبع اللوغو كاملاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('mark_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('mark_path');
        });
    }
};
