<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use LengthException;

/**
 * أطوال الأعمدة كما تفرضها MySQL — في اختباراتٍ تعمل على SQLite.
 *
 * SQLite لا تفرض VARCHAR(n): نصٌّ من ألف حرف يُحفَظ في عمودٍ من ٢٥٥ بلا
 * اعتراض. وMySQL بوضعها الصارم ترفضه بـ «Data too long» فتسقط الصفحة.
 * فكان نصٌّ تبنيه الشيفرة — «مصروف EX000001 — » ثم وصفٌ من ٢٥٥ حرفاً —
 * يمرّ في كل اختبار ويُسقط دفع المصروف في الإنتاج.
 *
 * الأطوال تُقرأ من ملفّات الترحيل نفسها (SQLite تُسقطها من المخطَّط)،
 * وكل حفظٍ عبر النموذج يُفحَص عليها.
 */
class ColumnLengths
{
    /** @var array<string, int>|null  "table.column" => length */
    protected static ?array $limits = null;

    public static function assertFits(Model $model): void
    {
        $limits = static::limits();
        $table = $model->getTable();

        foreach ($model->getDirty() as $column => $value) {
            $limit = $limits["{$table}.{$column}"] ?? null;

            // MySQL تعدّ الأحرف لا البايتات في utf8mb4
            if ($limit !== null && is_string($value) && mb_strlen($value) > $limit) {
                throw new LengthException(sprintf(
                    'Data too long for column %s.%s: %d > %d (MySQL تُسقط هذا الحفظ) — «%s…»',
                    $table, $column, mb_strlen($value), $limit, mb_substr($value, 0, 40),
                ));
            }
        }
    }

    /** @return array<string, int> */
    public static function limits(): array
    {
        if (static::$limits !== null) {
            return static::$limits;
        }

        $limits = [];

        foreach (File::files(database_path('migrations')) as $file) {
            preg_match_all("/Schema::(?:create|table)\\('([a-z_]+)'.*?\\n\\s*\\}\\);/s", $file->getContents(), $blocks, PREG_SET_ORDER);

            foreach ($blocks as [$block, $table]) {
                preg_match_all("/->(?:string|char)\\('([a-z_]+)'(?:,\\s*(\\d+))?\\)/", $block, $columns, PREG_SET_ORDER);

                foreach ($columns as $column) {
                    $limits[$table.'.'.$column[1]] = (int) ($column[2] ?? 255);
                }
            }
        }

        return static::$limits = $limits;
    }
}
