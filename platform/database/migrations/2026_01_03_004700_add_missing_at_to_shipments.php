<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «طرود لم تصل» (docs/plan/50): يُستلم الكشف الوارد بمسح كلّ طلبٍ بوحده، وما لم يُمسح
 * يُوسَم مفقوداً بوقته — فيُعرف الطلب المفقود بعينه لا الكيس كلّه. ويُمحى الوسم حين
 * يدخل الطرد مخزناً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->timestamp('missing_at')->nullable()->after('courier_settled_at');
            $table->index(['company_id', 'missing_at'], 'sh_missing_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_missing_idx');
            $table->dropColumn('missing_at');
        });
    }
};
