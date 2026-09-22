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
        $this->assertSame('5 شحنات', Arabic::shipments(5));
        $this->assertSame('30 شحنة', Arabic::shipments(30));
    }
}
