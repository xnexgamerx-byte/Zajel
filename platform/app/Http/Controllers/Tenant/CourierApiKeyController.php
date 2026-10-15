<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * مفاتيح ربط المندوب الوسيط (docs/plan/64): تُنشأ من صفحته، ويظهر المفتاح مرّةً واحدة ثم لا يُعرض.
 */
class CourierApiKeyController extends Controller
{
    public function store(Request $request, Courier $courier): RedirectResponse
    {
        abort_unless($courier->is_intermediary, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(array_keys(CourierApiKey::ABILITIES))],
            'expires_at' => ['required', 'date', 'after:today', 'before_or_equal:'.now()->addYears(2)->toDateString()],
            'allowed_ips' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'name' => 'اسم المفتاح',
            'abilities' => 'الصلاحيات',
            'expires_at' => 'تاريخ الانتهاء',
            'allowed_ips' => 'العناوين المسموحة',
        ]);

        $ips = $this->ips($data['allowed_ips'] ?? null);
        if ($ips === false) {
            return back()->withErrors(['allowed_ips' => 'عنوانٌ غير صحيح. اكتب عناوين IP مفصولةً بفاصلة أو سطر.'])->withInput();
        }

        $issued = CourierApiKey::issue(
            $courier,
            $data['name'],
            $data['abilities'],
            now()->parse($data['expires_at'])->endOfDay(),
            $ips ?: null,
            $request->user(),
        );

        return redirect()->route('couriers.show', $courier)
            ->with('success', 'أُنشئ المفتاح. انسخه الآن: لا يظهر مرّةً ثانية.')
            ->with('api_token', $issued['token']);
    }

    public function revoke(Courier $courier, CourierApiKey $apiKey): RedirectResponse
    {
        if ($apiKey->revoked_at === null) {
            $apiKey->revoke();
        }

        return redirect()->route('couriers.show', $courier)->with('success', 'أُلغي المفتاح، ويُرفض من الطلب التالي.');
    }

    /** @return list<string>|false فارغةٌ = من أيّ عنوان، وfalse = فيها ما ليس عنواناً */
    private function ips(?string $text): array|false
    {
        $ips = array_values(array_filter(array_map('trim', preg_split('/[\s,،]+/u', (string) $text) ?: [])));

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return false;
            }
        }

        return $ips;
    }
}
