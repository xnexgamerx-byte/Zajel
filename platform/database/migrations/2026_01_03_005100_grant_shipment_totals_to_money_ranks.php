<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| «إجمالي المبالغ والتوصيل» تحت قائمة الشحنات صلاحيةٌ جديدة (docs/plan/60). المرتبة تحلّ محلّ
| افتراضي الدور، فمرتبةٌ أُعدّت قبلها لا تحملها: تُعطى لكل مرتبةٍ ترى المال (money.view) — المحاسب
| والفرع كما في افتراضيّهما — ويُعطيها صاحب الشركة لغيرها من «الصلاحيات والمراتب».
*/
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('ranks')->get(['id', 'abilities']) as $rank) {
            $abilities = json_decode((string) $rank->abilities, true);

            if (is_array($abilities) && in_array('money.view', $abilities, true)
                && ! in_array('shipments.totals', $abilities, true)) {
                DB::table('ranks')->where('id', $rank->id)
                    ->update(['abilities' => json_encode([...$abilities, 'shipments.totals'])]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('ranks')->get(['id', 'abilities']) as $rank) {
            $abilities = json_decode((string) $rank->abilities, true);

            if (is_array($abilities) && in_array('shipments.totals', $abilities, true)) {
                DB::table('ranks')->where('id', $rank->id)
                    ->update(['abilities' => json_encode(array_values(array_diff($abilities, ['shipments.totals'])))]);
            }
        }
    }
};
