<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بعد تشغيل الربط (docs/plan/106):
 * - status_reasons: سبب «لم يُسلَّم» أو «مؤجّل» لكلّ حالةٍ عندهم بعينها («إلغاء الطلب» ← ألغى الزبون)،
 *   يتقدّم على السبب العامّ للربط.
 * - applied_at: متى طُبّق التحديث. يُحفظ أوّلاً «بانتظار التطبيق» ويُردّ عليهم فوراً، ثم يُطبَّق بعد الردّ،
 *   فلا تتجاوز مهلتهم (٣ ثوانٍ) فيعيدوه بعد دقائق.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aggregator_links', function (Blueprint $table) {
            $table->json('status_reasons')->nullable()->after('status_map');
        });

        Schema::table('aggregator_events', function (Blueprint $table) {
            $table->timestamp('applied_at')->nullable()->after('created_at');
            $table->index(['company_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::table('aggregator_events', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'result']);
            $table->dropColumn('applied_at');
        });

        Schema::table('aggregator_links', function (Blueprint $table) {
            $table->dropColumn('status_reasons');
        });
    }
};
