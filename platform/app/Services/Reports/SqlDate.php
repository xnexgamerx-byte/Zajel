<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/**
 * تجميع زمنيّ يعمل على المحرّكين.
 *
 * بقيّة النظام يستعمل whereDate فيتكفّل لارافيل بالفرق، أمّا التجميع
 * بالشهر فلا مقابل له: strftime في SQLite و date_format في MySQL.
 * الاختبارات تعمل على SQLite والإنتاج على MySQL، فتعبير محرّك واحد
 * يمرّ خضراء هنا ويسقط هناك.
 */
class SqlDate
{
    /** date() موجودة في المحرّكين بالمعنى نفسه. */
    public static function day(string $column): string
    {
        return "date({$column})";
    }

    public static function month(string $column): string
    {
        return static::sqlite()
            ? "strftime('%Y-%m', {$column})"
            : "date_format({$column}, '%Y-%m')";
    }

    protected static function sqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
}
