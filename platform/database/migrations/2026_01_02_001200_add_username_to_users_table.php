<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الدخول باسم المستخدم لا بالهاتف (App\Support\Username).
 *
 * كل حسابٍ موجود يأخذ رقم هاتفه اسماً: يدخل كما كان يدخل، ويُغيَّر الاسم
 * بعدها من شاشة المستخدمين. والرقم محفوظٌ بصيغةٍ واحدة (07 وتسعة أرقام)،
 * فهو اسمٌ صالح. وفريدٌ داخل الشركة كالهاتف — ولمستخدمي النواة (company_id
 * فارغ) يُفرض في التحقّق، كما في الهاتف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 32)->nullable()->after('phone');
        });

        DB::table('users')->whereNull('username')->update(['username' => DB::raw('phone')]);

        Schema::table('users', function (Blueprint $table) {
            $table->unique(['company_id', 'username']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'username']);
            $table->dropColumn('username');
        });
    }
};
