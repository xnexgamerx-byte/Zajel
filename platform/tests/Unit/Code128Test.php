<?php

namespace Tests\Unit;

use App\Support\Code128;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * باركود خاطئٌ لا يُقرأ، أو أسوأ: يُقرأ رقماً آخر. فالمتّجهات هنا محسوبةٌ
 * باليد من المواصفة، لا من المُرمِّز نفسه.
 */
class Code128Test extends TestCase
{
    /** @return array<string, array{string, list<int>}> */
    public static function vectors(): array
    {
        return [
            // المجموعة C: ١٠٥ + ١٨×١ + ٠×٢ + ٧٢×٣ = ٣٣٩ ≡ ٣٠ (mod ١٠٣)
            'رقم وصل زوجي الطول' => ['180072', [105, 18, 0, 72, 30, 106]],
            // المجموعة B: ١٠٤ + ١٧ + ١٨×٢ + ١٩×٣ + ٢٠×٤ + ٢١×٥ = ٣٩٩ ≡ ٩٠
            'أرقام فردية الطول'  => ['12345', [104, 17, 18, 19, 20, 21, 90, 106]],
            // ١٠٤ + ٣٣ + ٣٤×٢ + ١٣×٣ + ١٧×٤ = ٣١٢ ≡ ٣
            'حروف وشرطة'         => ['AB-1', [104, 33, 34, 13, 17, 3, 106]],
        ];
    }

    /** @param list<int> $codes */
    #[DataProvider('vectors')]
    public function test_it_encodes_by_the_spec(string $text, array $codes): void
    {
        $this->assertSame($codes, Code128::encode($text));
    }

    public function test_every_symbol_is_eleven_modules_and_the_stop_thirteen(): void
    {
        $modules = Code128::modules('180072');

        // البداية + ثلاثة أزواج + التحقّق = خمسة رموز، ثم التوقّف
        $this->assertSame(5 * 11 + 13, array_sum($modules));
        $this->assertCount(5 * 6 + 7, $modules);
    }

    public function test_the_svg_leaves_a_quiet_zone_on_both_sides(): void
    {
        $svg = Code128::svg('180072');

        $this->assertStringContainsString('viewBox="0 0 88 1"', $svg);   // ٦٨ + ١٠ + ١٠
        $this->assertStringStartsWith('<rect x="10" ', substr($svg, strpos($svg, '<rect')));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', Code128::dataUri('180072'));
    }

    public function test_it_refuses_what_it_cannot_encode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Code128::encode('وصل١٢');
    }

    public function test_it_refuses_an_empty_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Code128::encode('');
    }
}
