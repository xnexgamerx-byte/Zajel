<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الحالات كما يحدّثونها، بلا خيارات أسباب (docs/plan/107):
 * - failure_reasons.from_partner: سببٌ اسمه نصّ حالتهم («الغاء الطلب») يُنشأ وحده أوّل ما تصل. يراه الكول سنتر
 *   والمندوب والتاجر سبباً، ولا يظهر في قوائم الأسباب التي يختار منها أحد.
 * - aggregator_links.hook_*: آخر ما وصل منهم، وآخر ما رُفض ولماذا — ليُعرف من شاشة الربط لماذا لا تُقرأ تحديثاتهم.
 * - status_reasons (docs/plan/106) يُحذف: لا سبب يُختار لحالة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('failure_reasons', function (Blueprint $table) {
            $table->boolean('from_partner')->default(false)->after('is_active');
        });

        Schema::table('aggregator_links', function (Blueprint $table) {
            $table->timestamp('hook_last_at')->nullable();
            $table->timestamp('hook_rejected_at')->nullable();
            $table->string('hook_rejected_reason', 120)->nullable();
            $table->dropColumn('status_reasons');
        });
    }

    public function down(): void
    {
        Schema::table('aggregator_links', function (Blueprint $table) {
            $table->json('status_reasons')->nullable();
            $table->dropColumn(['hook_last_at', 'hook_rejected_at', 'hook_rejected_reason']);
        });

        Schema::table('failure_reasons', function (Blueprint $table) {
            $table->dropColumn('from_partner');
        });
    }
};
