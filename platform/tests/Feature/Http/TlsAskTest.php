<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * الخادم يطلب شهادة HTTPS لكل نطاقٍ يجيبه هذا المسار بـ ٢٠٠: فالحارس هنا
 * ألّا يجيب إلا لنطاقٍ نملكه فعلاً.
 */
class TlsAskTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, int}> */
    public static function hosts(): array
    {
        return [
            'النطاق الأساسي'          => ['zajel.iq', 200],
            'لوحة المنصّة'            => ['admin.zajel.iq', 200],
            'www'                    => ['www.zajel.iq', 200],
            'شركة مسجّلة'             => ['barq.zajel.iq', 200],
            'بأحرفٍ كبيرة'            => ['BARQ.zajel.iq', 200],
            'شركة غير موجودة'         => ['nope.zajel.iq', 404],
            'مستوىً أعمق'             => ['x.barq.zajel.iq', 404],
            'نطاقٌ غريب'              => ['barq.evil.com', 404],
            'ينتهي بالنطاق بلا نقطة'  => ['evilzajel.iq', 404],
            'فارغ'                    => ['', 404],
        ];
    }

    #[DataProvider('hosts')]
    public function test_it_allows_only_our_domains(string $host, int $status): void
    {
        config(['zajel.tenant_domain' => 'zajel.iq']);
        $this->makeCompany('barq', 'البرق');

        $this->get('/_tls/allowed?domain='.urlencode($host))->assertStatus($status);
    }
}
