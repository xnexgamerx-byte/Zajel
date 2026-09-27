<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «الملاحظات الثابتة» و«شحنات مرّت على مخزني» في المعتاد.
 *
 * الملاحظة الثابتة للتاجر تُلحق بكل شحنةٍ جديدة له («اتّصل قبل الوصول»، «لا
 * يُفتح الطرد»)، فلا تُكتب في كل شحنة ولا تُنسى في واحدة.
 *
 * ومرورُ الشحنة بمخزنٍ مكتوبٌ في سجلّها (hub_id في كل حدث)؛ والفهرس يجعل «ما مرّ
 * بمخزني» سؤالاً عن مراكز الفرع لا مسحاً للسجلّ كلّه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('fixed_note', 255)->nullable()->after('notes');
        });

        Schema::table('shipment_events', function (Blueprint $table) {
            $table->index(['company_id', 'hub_id', 'shipment_id'], 'se_hub_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_events', function (Blueprint $table) {
            $table->dropIndex('se_hub_idx');
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('fixed_note');
        });
    }
};
