<?php

namespace Tests\Feature\Http;

use App\Models\ShipmentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * أسماء أعمدة لا وجود لها — خطأٌ لا تكشفه الاختبارات.
 *
 * SQLite (وعليها تعمل الاختبارات) تُعامل المعرّف المزدوج التنصيص الذي
 * لا تجد له عموداً على أنه نصٌّ حرفيّ: فـ select "code" تُرجع الكلمة
 * «code» ولا ترمي. أما MySQL — وعليها يعمل الإنتاج — فترمي
 * Unknown column وتُسقط الصفحة كلّها.
 *
 * فالاختبارات كلّها تمرّ والشاشة تُبيّض في الإنتاج. جرى هذا فعلاً في
 * تقرير تتبّع التغييرات: shipment:id,code وليس في الشحنات عمود code
 * بل number. ولا يكشفه إلا هذا الحارس أو أول زبون يفتح الصفحة.
 */
class ColumnNameTest extends TestCase
{
    /*
    | بلا هذا يكون الحارس أجوف: قاعدة الاختبارات :memory: تُنشأ فارغة،
    | فـ Schema::hasTable تُكذّب كل جدول ويُتخطّى الفحص كلّه صامتاً
    | ويمرّ. جُرّب بإعادة الخطأ فمرّ — ولذلك تُعَدّ المطابقات أدناه.
    */
    use RefreshDatabase;

    /**
     * التحميل المُقيَّد بأعمدة: with('relation:col,col').
     *
     * اسم العلاقة يُحَلّ إلى نموذج بالاصطلاح (shipment -> Shipment،
     * shipments -> Shipment)، وما لا يُحَلّ يُترك — الحارس يمنع الخطأ
     * الشائع ولا يدّعي إحاطةً بكل صيغة.
     */
    public function test_constrained_eager_loads_name_columns_that_exist(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->phpFiles() as $file) {
            preg_match_all(
                "/['\"]([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*):([A-Za-z0-9_,\s]+)['\"]/",
                $file->getContents(),
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as [$whole, $path, $columns]) {
                // آخر جزء من المسار هو العلاقة المعنيّة: shipment.merchant:...
                $relation = Str::afterLast($path, '.');
                $table = $this->tableFor($relation);

                if ($table === null) {
                    continue;
                }

                foreach (array_filter(array_map('trim', explode(',', $columns))) as $column) {
                    $checked++;

                    if (! Schema::hasColumn($table, $column)) {
                        $offenders[] = $file->getRelativePathname()." — {$whole} (لا عمود {$table}.{$column})";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "أعمدة لا وجود لها في تحميل مُقيَّد — تمرّ على SQLite وتسقط على MySQL:\n"
            .implode("\n", $offenders),
        );

        // حارسٌ لا يفحص شيئاً يمرّ دائماً: يُطمَأنّ إليه وهو لا يحرس
        $this->assertGreaterThan(10, $checked, 'الحارس لم يفحص أعمدة — تحقّق من المُطابِق أو من المخطَّط.');
    }

    /**
     * أنواع الأحداث: ما تكتبه الأفعال وما تعرضه الشاشة قائمةٌ واحدة.
     *
     * كانت الشاشة تعرض «مسح» و«إسناد» ولا يكتبهما أحد، وتُخفي «الكيس»
     * و«تأكيد المبلغ» و«استلام الراجع» وهي تُكتب — فالمُرشِّح يَعِد بفرزٍ
     * لا يقع، والجدول يعرض السلاسل الإنجليزية الخام حين لا يجد ترجمة.
     */
    public function test_every_written_event_type_has_a_label(): void
    {
        $written = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                "/'event_type'\s*=>\s*'([a-z_]+)'|->log\(\s*\\$[A-Za-z_]+\s*,\s*'([a-z_]+)'/",
                $file->getContents(),
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                $type = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

                if ($type !== '') {
                    $written[$type] = true;
                }
            }
        }

        $this->assertNotEmpty($written, 'لم يُعثر على أي نوع حدث مكتوب — تحقّق من المُطابِق.');

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($written), array_keys(ShipmentEvent::TYPES))),
            'أنواع أحداث تُكتب ولا اسم عربيّ لها في ShipmentEvent::TYPES.',
        );
    }

    /** @return iterable<\Symfony\Component\Finder\SplFileInfo> */
    protected function phpFiles(): iterable
    {
        foreach ([app_path(), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                if (in_array($file->getExtension(), ['php'], true)) {
                    yield $file;
                }
            }
        }
    }

    protected function tableFor(string $relation): ?string
    {
        $class = 'App\\Models\\'.Str::studly(Str::singular($relation));

        if (! class_exists($class) || ! is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
            return null;
        }

        $table = (new $class)->getTable();

        return Schema::hasTable($table) ? $table : null;
    }
}
