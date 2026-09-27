<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * التطبيق صار بتوقيت بغداد (config/app.php).
 *
 * على MySQL لا يُنقل شيء: أعمدة TIMESTAMP تحفظ اللحظة نفسها، والجلسة صارت
 * تقرؤها بفرق بغداد. أمّا SQLite فيحفظ النصّ كما كُتب — وكُتب حتى الآن بتوقيت
 * UTC، بيد التطبيق وبافتراض القاعدة معاً — فيُزاد عليه الفرق مرّةً ليصير كما
 * يُكتب اليوم. وقاعدةٌ جديدة تمرّ هنا وجداولها فارغة، فلا يُزاد شيء.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift(now()->utcOffset());
    }

    public function down(): void
    {
        $this->shift(-now()->utcOffset());
    }

    private function shift(int $minutes): void
    {
        if ($minutes === 0 || DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $modifier = sprintf('%+d minutes', $minutes);

        foreach (Schema::getTables() as $table) {
            if (str_starts_with($table['name'], 'sqlite_')) {
                continue;
            }

            $columns = collect(Schema::getColumns($table['name']))
                ->filter(fn (array $column) => $column['type_name'] === 'datetime')
                ->pluck('name');

            if ($columns->isEmpty()) {
                continue;
            }

            // جولةٌ واحدة على كل جدول. وNULL يبقى NULL، ونصٌّ لا يُقرأ تاريخاً يبقى
            // كما هو بدل أن تمحوه datetime()
            $sets = $columns->map(fn (string $column) => sprintf(
                '"%1$s" = case when datetime("%1$s") is null then "%1$s" else datetime("%1$s", ?) end', $column,
            ));

            DB::update(
                sprintf('update "%s" set %s', $table['name'], $sets->implode(', ')),
                array_fill(0, $columns->count(), $modifier),
            );
        }
    }
};
