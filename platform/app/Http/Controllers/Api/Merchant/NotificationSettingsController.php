<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Support\Push\NotifyPrefs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «الإشعارات» في تطبيق التاجر (docs/plan/86): أيّها يصل هاتفه — وصلت شحنتك، للمعالجة، تمت معالجتها، تغيّر
 * المبلغ — وبصوتٍ أم بلا صوت. لكلّ مستخدمٍ اختياره، والجرس يبقى يحمل كلّ شيء.
 */
class NotificationSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::json($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $keys = [...array_keys(NotifyPrefs::KINDS), 'sound'];
        $data = $request->validate(array_fill_keys($keys, ['sometimes', 'boolean']));

        $user = $request->user();
        $user->forceFill(['notify_prefs' => array_merge(NotifyPrefs::of($user), array_map('boolval', $data))])->save();

        return response()->json(['message' => 'حُفظ اختيارك للإشعارات.', 'data' => self::json($request)]);
    }

    /** @return array{kinds: list<array{key: string, label: string, on: bool}>, sound: bool} */
    private static function json(Request $request): array
    {
        $prefs = NotifyPrefs::of($request->user()->refresh());

        return [
            'kinds' => collect(NotifyPrefs::KINDS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'on' => $prefs[$key]])
                ->values()->all(),
            'sound' => $prefs['sound'],
        ];
    }
}
