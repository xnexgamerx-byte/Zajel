<?php

namespace Tests\Unit;

use App\Support\ScanCode;
use PHPUnit\Framework\TestCase;

/**
 * ما يكتبه الماسح: باركود الوصل، أو رابط التتبّع من رمز QR الذي عليه — ولو كانت
 * لغة الجهاز العربية فكتب الماسح حروف الرابط بمواضعها في اللوحة العربية.
 */
class ScanCodeTest extends TestCase
{
    public function test_a_barcode_reads_as_itself(): void
    {
        $scan = ScanCode::read(' 000123 ');

        $this->assertSame('000123', $scan->code);
        $this->assertFalse($scan->isLink());
        $this->assertTrue($scan->usable());

        // أرقامٌ عربية من لوحةٍ أو هاتف، ومسافةٌ وسط الرقم المكتوب باليد
        $this->assertSame('90000045', ScanCode::read('٩٠٠٠٠ ٠٤٥')->code);
    }

    public function test_the_qr_link_reads_as_the_shipment_number_and_its_token(): void
    {
        $scan = ScanCode::read('https://zajel.example.iq/t/000123/0123456789abcdef');

        $this->assertTrue($scan->isLink());
        $this->assertSame('000123', $scan->code);
        $this->assertSame('0123456789abcdef', $scan->token);

        // ماسحٌ يكتب بالحروف الكبيرة، وعلامة اتّجاهٍ خفيّة في أوّله
        $upper = ScanCode::read("\u{200F}HTTPS://ZAJEL.EXAMPLE.IQ/T/000123/0123456789ABCDEF");
        $this->assertSame(['000123', '0123456789abcdef'], [$upper->code, $upper->token]);
    }

    public function test_a_qr_link_typed_on_the_arabic_keyboard_is_read_back(): void
    {
        // «http://zajel.localhost/t/000123/0123456789abcdef» واللوحة على العربية
        $scan = ScanCode::read('اففح:ظظئشتثمزمخؤشماخسفظفظ000123ظ0123456789شلاؤيثب');

        $this->assertTrue($scan->isLink());
        $this->assertSame('000123', $scan->code);
        $this->assertSame('0123456789abcdef', $scan->token);
    }

    public function test_ordinary_arabic_text_is_left_alone(): void
    {
        // ليس رابطاً: لا يُقلب اسمٌ عربي حروفاً لاتينية
        $scan = ScanCode::read('علي حسين');

        $this->assertFalse($scan->isLink());
        $this->assertSame('عليحسين', $scan->code);
    }

    public function test_nothing_or_too_long_is_not_usable(): void
    {
        $this->assertFalse(ScanCode::read('')->usable());
        $this->assertFalse(ScanCode::read(str_repeat('9', 41))->usable());
    }
}
