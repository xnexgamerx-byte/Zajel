<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function host(): string
    {
        return 'zajel.'.config('zajel.tenant_domain');
    }

    public function test_every_page_carries_the_security_headers(): void
    {
        $this->seedReference();
        $this->makeCompany('zajel', 'الزاجل');

        $response = $this->get('http://'.$this->host().'/login')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');

        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);

        // دخول المنصّة إلى شركة يُرسَل على نطاقٍ ويُحوَّل إلى آخر
        $this->assertStringContainsString("form-action 'self' *.".config('zajel.tenant_domain').':*', $policy);

        // http المحلّي لا يُقفَل على https
        $response->assertHeaderMissing('Strict-Transport-Security');
        $response->assertHeaderMissing('Cross-Origin-Opener-Policy');
    }

    public function test_https_is_pinned_only_over_https(): void
    {
        $this->seedReference();
        $this->makeCompany('zajel', 'الزاجل');

        $this->get('https://'.$this->host().'/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }

    public function test_errors_carry_them_too(): void
    {
        $this->get('http://nope.'.config('zajel.tenant_domain').'/shipments')
            ->assertNotFound()
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    /**
     * سياسة المحتوى تحجب بصمت: مكتبةٌ تُضاف من CDN تعمل على جهاز
     * المطوّر إن لم يكن يقرأ الرؤوس، وتختفي في الإنتاج بلا خطأ على
     * الشاشة. فكل مصدرٍ خارجي في أي صفحة يجب أن تسمح به السياسة.
     */
    public function test_every_external_resource_in_the_views_is_allowed_by_the_policy(): void
    {
        $allowed = [
            'script-src' => [],
            'style-src'  => SecurityHeaders::EXTERNAL['style-src'],
            'font-src'   => SecurityHeaders::EXTERNAL['font-src'],
            'img-src'    => [],
        ];

        $checked = 0;
        $blocked = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = $file->getContents();
            $loads = [];

            foreach ($this->tags($source, 'script') as $tag) {
                $loads[] = ['script-src', $this->attribute($tag, 'src')];
            }

            foreach ($this->tags($source, 'link') as $tag) {
                if (preg_match('/rel="stylesheet"/i', $tag)) {
                    $loads[] = ['style-src', $this->attribute($tag, 'href')];
                }
            }

            foreach ($this->tags($source, 'img') as $tag) {
                $loads[] = ['img-src', $this->attribute($tag, 'src')];
            }

            foreach ($loads as [$directive, $url]) {
                if ($url === null || ! preg_match('#^(https?:)?//#', $url)) {
                    continue;
                }

                $checked++;
                $origin = preg_replace('#^((?:https?:)?//[^/]+).*$#', '$1', $url);

                if (! in_array($origin, $allowed[$directive], true)) {
                    $blocked[] = $file->getRelativePathname()." — {$directive} {$url}";
                }
            }
        }

        // الخطوط في قالبٍ واحد (partials/fonts) تضمّه كل التخطيطات — إن لم يرَه الحارس فهو لا يرى شيئاً
        $this->assertGreaterThanOrEqual(1, $checked);
        $this->assertSame([], $blocked, "مصدرٌ خارجي تحجبه سياسة المحتوى:\n".implode("\n", $blocked));

        foreach (array_filter($allowed) as $directive => $origins) {
            foreach ($origins as $origin) {
                $this->assertStringContainsString($origin, SecurityHeaders::policy());
            }
        }
    }

    private function tags(string $source, string $name): array
    {
        preg_match_all('/<'.$name.'\b[^>]*>/i', $source, $matches);

        return $matches[0];
    }

    private function attribute(string $tag, string $name): ?string
    {
        return preg_match('/\b'.$name.'="([^"]*)"/i', $tag, $m) ? $m[1] : null;
    }
}
