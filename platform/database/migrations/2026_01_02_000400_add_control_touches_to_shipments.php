<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ثلاث لمسات رقابية من النظام المرجعي.
 *
 * تنفيذها ساعات وقيمتها شهور: كشف الإيصالات المكرّرة يمنع شحنة تُحاسَب
 * مرّتين، و«واصل إجباري» بحقل سبب إلزامي يمنع أشيع تلاعب في هذا المجال
 * — أن يُعلَن التسليم من غير تسليم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // بصمة الشحنة: تاجر + هاتف + مبلغ. تكرارها خلال أيام قليلة
            // يعني غالباً إدخالاً مزدوجاً لا طلباً ثانياً.
            $table->string('dedupe_hash', 40)->nullable()->after('merchant_reference');
            $table->foreignId('duplicate_of_id')->nullable()->after('dedupe_hash');
            $table->timestamp('duplicate_cleared_at')->nullable()->after('duplicate_of_id');

            // التسليم القسريّ: من غير مسار الحالات الطبيعي، بسبب مكتوب
            $table->boolean('is_forced')->default(false)->after('status');
            $table->string('forced_reason', 255)->nullable()->after('is_forced');
            $table->foreignId('forced_by_user_id')->nullable()->after('forced_reason');

            $table->index(['company_id', 'dedupe_hash'], 'sh_dedupe_idx');
            $table->index(['company_id', 'is_forced', 'status_changed_at'], 'sh_forced_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_dedupe_idx');
            $table->dropIndex('sh_forced_idx');
            $table->dropColumn([
                'dedupe_hash', 'duplicate_of_id', 'duplicate_cleared_at',
                'is_forced', 'forced_reason', 'forced_by_user_id',
            ]);
        });
    }
};
