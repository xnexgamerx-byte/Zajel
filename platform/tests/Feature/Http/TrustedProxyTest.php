<?php

namespace Tests\Feature\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * عنوان الزائر يُبنى عليه حدّ محاولات الدخول والتتبّع.
 *
 * خلف وكيل (Railway) يجب تصديقه وإلا صار كل الزوّار عنواناً واحداً؛ وبلا
 * وكيل (خادمنا) يجب ألّا يُصدَّق وإلا كتب كل زائرٍ عنوانه. والنطاق لا
 * يُصدَّق في الحالين: منه تُعرَف الشركة.
 */
class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_probe', fn (Request $request) => implode('|', [
            $request->ip(), $request->isSecure() ? 'https' : 'http', $request->getHost(),
        ]));
    }

    private function probe(string $from = '10.0.0.7', string $forwardedFor = '203.0.113.9'): string
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $from])
            ->withHeaders([
                'X-Forwarded-For'   => $forwardedFor,
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host'  => 'other.zajel.iq',
            ])
            ->get('http://barq.zajel.iq/_probe')
            ->getContent();
    }

    public function test_without_a_proxy_the_headers_are_ignored(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->assertSame('10.0.0.7|http|barq.zajel.iq', $this->probe());
    }

    public function test_behind_a_trusted_proxy_the_visitor_is_the_forwarded_one_but_the_host_is_not(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->assertSame('203.0.113.9|https|barq.zajel.iq', $this->probe());
    }

    public function test_an_empty_setting_trusts_no_proxy(): void
    {
        // compose يمرّره فارغاً على الخادم المكشوف
        config(['trustedproxy.proxies' => '']);

        $this->assertSame('10.0.0.7|http|barq.zajel.iq', $this->probe());
    }

    public function test_behind_cloudflare_only_its_ranges_are_trusted(): void
    {
        // deploy/cloudflare-ranges.sh: عناوين Cloudflare بفواصل
        config(['trustedproxy.proxies' => '173.245.48.0/20,162.158.0.0/15,2400:cb00::/32']);

        // من Cloudflare: الزائر آخر ما أضافته، لا أوّل ما كتبه هو في الرأس
        $this->assertSame('203.0.113.9|https|barq.zajel.iq', $this->probe('162.158.1.1', '6.6.6.6, 203.0.113.9'));

        // من غيرها — من وصل عنوان الخادم مباشرةً — الرأس لا يُصدَّق
        $this->assertSame('198.51.100.4|http|barq.zajel.iq', $this->probe('198.51.100.4', '203.0.113.9'));
    }
}
