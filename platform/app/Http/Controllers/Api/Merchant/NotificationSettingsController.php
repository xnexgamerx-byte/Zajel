<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Http\Controllers\Controller;
use App\Models\PushDevice;
use App\Support\Push\Fcm;
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

    /**
     * «جرّب الإشعار» (docs/plan/89): إشعارٌ إلى هواتف هذا الحساب وحده الآن، ويعود بما جرى بكلام التاجر — فيُعرف
     * أين العطل: مفتاح الخادم، أو هاتفٌ لم يُسجَّل، أو رفض Firebase.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();
        $devices = PushDevice::query()->where('user_id', $user->id)->pluck('token')->all();
        $report = Fcm::deliver(
            $devices,
            'تجربة الإشعارات',
            'وصل هذا الإشعار إلى هاتفك: الإشعارات تعمل.',
            ['kind' => 'test'],
            NotifyPrefs::channel($user),
        );

        if ($report['dead'] !== []) {
            PushDevice::query()->whereIn('token', $report['dead'])->delete();
        }

        $message = match ($report['error']) {
            null => 'أُرسل إشعارٌ تجريبيّ إلى هاتفك. إن لم يظهر في شريط الهاتف فالإشعارات مطفأةٌ للتطبيق في إعدادات الهاتف.',
            'no_key' => 'الخادم غير مهيّأ لإشعارات الهاتف بعد: مفتاح Firebase غير موجود. راجع شركة التوصيل.',
            'no_device' => 'هاتفك غير مسجّل للإشعارات. اسمح للتطبيق بالإشعارات ثم أعد فتحه.',
            'auth' => 'لم يقبل Firebase مفتاح الخادم. راجع شركة التوصيل.',
            'expired' => 'رمز هاتفك قديم وحُذف. أعد فتح التطبيق ليُسجَّل من جديد.',
            default => 'رفض Firebase الإرسال إلى هاتفك. راجع شركة التوصيل.',
        };

        return response()->json(['ok' => $report['error'] === null, 'message' => $message, 'sent' => $report['sent']],
            $report['error'] === null ? 200 : 422);
    }

    /** @return array{kinds: list<array{key: string, label: string, on: bool}>, sound: bool, push: array{server: bool, devices: int}} */
    private static function json(Request $request): array
    {
        $prefs = NotifyPrefs::of($request->user()->refresh());

        return [
            'kinds' => collect(NotifyPrefs::KINDS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'on' => $prefs[$key]])
                ->values()->all(),
            'sound' => $prefs['sound'],
            // حال إشعارات الهاتف (docs/plan/89): مفتاح الخادم، وهواتف هذا الحساب المسجّلة
            'push' => [
                'server' => Fcm::enabled(),
                'devices' => PushDevice::query()->where('user_id', $request->user()->id)->count(),
            ],
        ];
    }
}
