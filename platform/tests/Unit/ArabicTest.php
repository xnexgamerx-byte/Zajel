<?php

namespace Tests\Unit;

use App\Support\Arabic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * تمييز العدد. «١٢ أيام» أوّل ما يلاحظه المستخدم العربي في نظام
 * بُنيت نصوصه بالسَّلسَلة لا بالقاعدة.
 */
class ArabicTest extends TestCase
{
    public function test_weekday_names_are_whole_words_not_letters(): void
    {
        // صيغة Carbon القصيرة للعربية حرفٌ واحد («س»)؛ هذه كلمات تُقرأ تحت الرسم
        $names = array_map(
            fn (int $day) => Arabic::weekday(new \DateTimeImmutable("2026-09-{$day}")),
            range(20, 26),
        );

        $this->assertSame(['أحد', 'اثنين', 'ثلاثاء', 'أربعاء', 'خميس', 'جمعة', 'سبت'], $names);
    }

    #[DataProvider('dayCases')]
    public function test_it_agrees_the_counted_noun(int $n, string $expected): void
    {
        $this->assertSame($expected, Arabic::days($n));
    }

    public static function dayCases(): array
    {
        return [
            'واحد'          => [1, 'يوم واحد'],
            'اثنان'         => [2, 'يومان'],
            'ثلاثة جمع'     => [3, '3 أيام'],
            'عشرة جمع'      => [10, '10 أيام'],
            'أحد عشر مفرد'  => [11, '11 يوماً'],
            'اثنا عشر مفرد' => [12, '12 يوماً'],
            'سبعة وثلاثون'  => [37, '37 يوماً'],
            'مئة'           => [100, '100 يوماً'],
        ];
    }

    public function test_the_same_rule_holds_for_other_nouns(): void
    {
        $this->assertSame('شحنة واحدة', Arabic::shipments(1));
        $this->assertSame('شحنتان', Arabic::shipments(2));

        // «١٢ كيساً» لا «١٢ أكياس»، و«٣ أكياس» لا «٣ كيساً»
        $this->assertSame('كيس واحد', Arabic::bags(1));
        $this->assertSame('كيسان', Arabic::bags(2));
        $this->assertSame('3 أكياس', Arabic::bags(3));
        $this->assertSame('12 كيساً', Arabic::bags(12));

        $this->assertSame('تاجران', Arabic::merchants(2));
        $this->assertSame('7 تجّار', Arabic::merchants(7));
        $this->assertSame('40 تاجراً', Arabic::merchants(40));
        $this->assertSame('طرد واحد', Arabic::parcels(1));
        $this->assertSame('4 طرود', Arabic::parcels(4));
        $this->assertSame('15 طرداً', Arabic::parcels(15));
        $this->assertSame('5 شحنات', Arabic::shipments(5));
        $this->assertSame('30 شحنة', Arabic::shipments(30));
    }

    /** أسماءٌ يكتبها كلٌّ على هواه وهي اسمٌ واحد */
    public function test_fold_makes_spellings_of_one_name_equal(): void
    {
        $this->assertSame(Arabic::fold('الأعظمية'), Arabic::fold('الاعظميه'));
        $this->assertSame(Arabic::fold('أبو نؤاس'), Arabic::fold('ابو نواس'));
        $this->assertSame(Arabic::fold('رئاسة المعلمين'), Arabic::fold('رياسه المعلمين'));
        $this->assertSame(Arabic::fold('الـدورة'), Arabic::fold('الدورة'));
        $this->assertSame(Arabic::fold('الدورة - الصحة'), Arabic::fold('الدورة/الصحة'));
        $this->assertSame(Arabic::fold('الدورة - الصحة'), Arabic::fold('  الدورة   الصحة '));
        $this->assertSame(Arabic::fold('حي ١٤ تموز'), Arabic::fold('حي 14 تموز'));
        $this->assertSame(Arabic::fold('مُسْتَشْفى'), Arabic::fold('مستشفي'));
        $this->assertSame('baghdad', Arabic::fold(' Baghdad '));

        // والمختلف يبقى مختلفاً
        $this->assertNotSame(Arabic::fold('الراشدية'), Arabic::fold('الرشيدية'));
        $this->assertNotSame(Arabic::fold('حي الخضراء'), Arabic::fold('المنطقة الخضراء'));
        $this->assertSame('', Arabic::fold(null));
    }

    /** اسم المنطقة يُكتب بالتعريف وبدونه، وبـ«حي» أو «منطقة» قبله وبدونهما */
    public function test_loose_fold_lets_an_area_be_written_without_its_article_or_hay(): void
    {
        $this->assertSame(Arabic::looseFold('الصالحية'), Arabic::looseFold('صالحيه'));
        $this->assertSame(Arabic::looseFold('حي الرشاد'), Arabic::looseFold('الرشاد'));
        $this->assertSame(Arabic::looseFold('حي النقيب'), Arabic::looseFold('نقيب'));
        $this->assertSame(Arabic::looseFold('منطقة الغدير'), Arabic::looseFold('الغدير'));

        // «المنطقة الخضراء» اسمٌ بذاته، لا «منطقة» تسبق «الخضراء»؛ و«حي» وحدها تبقى
        $this->assertNotSame(Arabic::looseFold('حي الخضراء'), Arabic::looseFold('المنطقة الخضراء'));
        $this->assertNotSame(Arabic::looseFold('حي الرشاد'), Arabic::looseFold('شارع الرشاد'));
        $this->assertSame('حي', Arabic::looseFold('حي'));
    }
}
