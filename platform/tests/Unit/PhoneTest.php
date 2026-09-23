<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function numbers(): array
    {
        return [
            'محلّي'                => ['07701234567', '07701234567'],
            'بمسافات وشَرطات'       => ['0770 123-4567', '07701234567'],
            'دوليّ بـ +'            => ['+9647701234567', '07701234567'],
            'دوليّ بـ 00'           => ['009647701234567', '07701234567'],
            'بلا صفر'              => ['7701234567', '07701234567'],
            'أرقام عربية'          => ['٠٧٧٠١٢٣٤٥٦٧', '07701234567'],
            'أرضيّ ليس جوالاً'      => ['017181234', null],
            'قصير'                 => ['0770123', null],
            'فارغ'                 => ['', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_every_shape_normalises_to_one_form(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, Phone::normalise($raw));
    }

    /** واتساب لا يقبل الصفر المحلّيّ ولا «+». */
    public function test_the_whatsapp_link_is_international_without_the_leading_zero(): void
    {
        $this->assertSame('https://wa.me/9647701234567', Phone::whatsappUrl('0770 123 4567'));
        $this->assertSame('https://wa.me/9647701234567?text=%D9%85%D8%B1%D8%AD%D8%A8%D8%A7', Phone::whatsappUrl('07701234567', 'مرحبا'));
        $this->assertNull(Phone::whatsappUrl('غير رقم'));
    }
}
