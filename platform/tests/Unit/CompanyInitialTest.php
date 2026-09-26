<?php

namespace Tests\Unit;

use App\Models\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * حرف الشعار في أنظمة الشركات حرفُ الشركة لا حرف المنصّة. و«ال» في أوّل
 * أكثر الأسماء، فحرفها بعده: «ب» للبرق لا «ا».
 */
class CompanyInitialTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            'بـ«ال»'          => ['البرق', 'ب'],
            'اسمٌ من كلمتين'  => ['الزاجل للتوصيل', 'ز'],
            'بلا «ال»'        => ['برايم', 'ب'],
            'بالإنجليزية'     => ['Prime Express', 'P'],
            'بمسافاتٍ حوله'   => ['  الزعيم ', 'ز'],
            '«ال» وحدها'      => ['ال', 'ا'],
        ];
    }

    #[DataProvider('names')]
    public function test_the_initial_is_the_first_letter_after_the_article(string $name, string $initial): void
    {
        $this->assertSame($initial, (new Company)->forceFill(['name' => $name])->initial());
    }
}
