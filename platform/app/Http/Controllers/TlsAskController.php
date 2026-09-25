<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Scopes\CurrentCompanyScope;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * يسأله خادم الويب (Caddy) قبل أن يطلب شهادة HTTPS لنطاقٍ لم يرَه من قبل.
 *
 * كل شركةٍ نطاقٌ فرعيّ، فالشهادة تُصدَر عند أول زيارةٍ له — بلا إعدادٍ لكل
 * شركة. ولولا هذا السؤال لطلب الخادم شهادةً لأيّ اسمٍ يُكتَب في المتصفّح،
 * فيُستنفَد حدّ Let's Encrypt بأسماءٍ مخترعة.
 *
 * يجيب ٢٠٠ للنطاق الأساسي ولـ admin وwww، ولنطاق كل شركةٍ مسجّلة؛ و٤٠٤ لما سواها.
 */
class TlsAskController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $host = strtolower(trim((string) $request->query('domain')));
        $base = strtolower((string) config('zajel.tenant_domain'));

        return response('', $this->allowed($host, $base) ? 200 : 404);
    }

    private function allowed(string $host, string $base): bool
    {
        if ($base === '' || $host === '') {
            return false;
        }

        if (in_array($host, [$base, 'www.'.$base, 'admin.'.$base], true)) {
            return true;
        }

        if (! str_ends_with($host, '.'.$base)) {
            return false;
        }

        $slug = substr($host, 0, -(strlen($base) + 1));

        return ! str_contains($slug, '.')
            && Company::withoutGlobalScope(CurrentCompanyScope::class)->where('slug', $slug)->exists();
    }
}
