<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تجاوزات الصلاحيات لكل مستخدم.
 *
 * null يعني «افتراضي دوره» — وهو الحال الغالب، فلا يُنسَخ لكل مستخدم
 * جدولٌ يتقادم حين تتغيّر سياسة الدور. والتجاوز يُكتب فقط حين يُقصَد:
 * محاسب أُعطي صلاحية إسناد، أو موظّف عمليات مُنع من تغيير الحالات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
