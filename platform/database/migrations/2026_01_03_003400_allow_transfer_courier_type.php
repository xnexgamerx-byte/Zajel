<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * مندوب النقل بين الفروع (الوثيقة ٣٣ §٣) نوعٌ رابع للمندوب: «transfer».
 *
 * عمود النوع على MySQL قائمةٌ مغلقة (enum) فتُفتح له، وإلّا رُفض حفظه. والقيمة
 * تُضاف في آخر القائمة: تعديلٌ في وصف الجدول وحده، بلا نسخٍ لصفوفه. أمّا SQLite
 * فالعمود فيه نصٌّ بلا قيد، فلا شيء يُعدَّل.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->types(['delivery', 'pickup', 'both', 'transfer']);
    }

    public function down(): void
    {
        // مندوب النقل يعود مندوب توصيل قبل أن تضيق القائمة: لا يُحذف أحد
        DB::table('couriers')->where('type', 'transfer')->update(['type' => 'delivery']);

        $this->types(['delivery', 'pickup', 'both']);
    }

    /** @param  list<string>  $types */
    private function types(array $types): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $list = implode(', ', array_map(fn (string $type) => "'{$type}'", $types));

        DB::statement("alter table couriers modify type enum({$list}) not null default 'delivery'");
    }
};
