<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «إضافة كود لتسليم الشحنة» و«وضع شحنات العميل تحت المراجعة» في المعتاد.
 *
 * كود التسليم: رقمٌ يعرفه التاجر وزبونه ولا يُطبع على الطرد؛ المندوب لا يسجّل
 * التسليم إلّا به — فلا «تسليمٌ» على الورق لطردٍ لم يصل.
 *
 * والمراجعة: شحنات تاجرٍ قيد التدقيق تُعلَّق عند إنشائها، ولا تخرج مع مندوب
 * حتى يجيزها موظّف — ومتى ومن.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->boolean('requires_delivery_code')->default(false)->after('portal_access');
            $table->boolean('hold_for_review')->default(false)->after('requires_delivery_code');
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('delivery_code', 6)->nullable()->after('barcode');
            $table->timestamp('review_hold_at')->nullable()->after('is_forced');
            $table->timestamp('reviewed_at')->nullable()->after('review_hold_at');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('reviewed_at');

            $table->index(['company_id', 'review_hold_at', 'reviewed_at'], 'sh_review_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex('sh_review_idx');
            $table->dropColumn(['delivery_code', 'review_hold_at', 'reviewed_at', 'reviewed_by_user_id']);
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['requires_delivery_code', 'hold_for_review']);
        });
    }
};
