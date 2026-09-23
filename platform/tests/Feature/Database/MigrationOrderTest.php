<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الترحيلات بترتيبٍ يقبله MySQL.
 *
 * SQLite تقبل مفتاحاً أجنبياً إلى جدولٍ لم يُنشأ بعد — لا تتحقّق من
 * الهدف ساعة الإنشاء. MySQL ترفضه (1824 Failed to open the referenced
 * table). فكان جدول العدّادات يُنشأ قبل جدول الشركات، وكل اختبارٍ أخضر،
 * والترحيل الثالث يُسقط أول نشرٍ على MySQL قبل أن يوجد جدول واحد.
 *
 * الحارس يقرأ الترحيلات بترتيب تشغيلها ويُلزم كل مفتاحٍ أجنبي بجدولٍ
 * أنشأه ترحيلٌ سابق أو الترحيلُ نفسه.
 */
class MigrationOrderTest extends TestCase
{
    public function test_every_foreign_key_targets_a_table_created_earlier(): void
    {
        $created = [];
        $checked = 0;
        $early = [];

        $files = collect(File::files(database_path('migrations')))
            ->sortBy(fn ($file) => $file->getFilename())
            ->values();

        foreach ($files as $file) {
            $source = $file->getContents();

            preg_match_all("/Schema::create\\(\\s*'(\\w+)'/", $source, $own);

            foreach ($this->foreignTargets($source) as $target) {
                $checked++;

                if (! isset($created[$target]) && ! in_array($target, $own[1], true)) {
                    $early[] = $file->getFilenameWithoutExtension()." → {$target}";
                }
            }

            foreach ($own[1] as $table) {
                $created[$table] = true;
            }
        }

        // حارسٌ لا يجد شيئاً يفحصه يمرّ كاذباً
        $this->assertGreaterThan(90, $checked, 'لم يُعثر على المفاتيح الأجنبية — تغيّرت صيغتها؟');

        $this->assertSame([], $early, "مفتاحٌ أجنبي إلى جدولٍ يُنشأ لاحقاً — MySQL ترفضه:\n".implode("\n", $early));
    }

    /** foreignId('x_id')->…->constrained(['t']) و ->references(…)->on('t') */
    private function foreignTargets(string $source): array
    {
        $targets = [];

        preg_match_all(
            "/foreignId\\(\\s*'(\\w+)'\\s*\\)(?:->\\w+\\([^)]*\\))*?->constrained\\(\\s*(?:'(\\w+)')?/",
            $source,
            $constrained,
            PREG_SET_ORDER,
        );

        foreach ($constrained as $match) {
            $targets[] = ($match[2] ?? '') !== ''
                ? $match[2]
                : Str::plural(Str::beforeLast($match[1], '_id'));
        }

        preg_match_all("/->on\\(\\s*'(\\w+)'\\s*\\)/", $source, $on);

        return [...$targets, ...$on[1]];
    }
}
