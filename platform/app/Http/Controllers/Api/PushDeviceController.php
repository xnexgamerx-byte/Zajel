<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushDevice;
use App\Support\Push\Fcm;
use App\Support\Push\StaffPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** تسجيل هاتف الإشعارات بعد الدخول، وإلغاؤه عند الخروج (docs/plan/82). */
class PushDeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        // الرمز نفسه قد يكون مربوطاً بشركةٍ أخرى من الهاتف نفسه: يُعاد ربطه بصاحبه الحالي
        PushDevice::withoutGlobalScopes()->where('token', $data['token'])->delete();
        PushDevice::create([
            'user_id' => $request->user()->id,
            'token' => $data['token'],
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);

        return response()->json(['message' => 'سُجّل الهاتف للإشعارات.'], 201);
    }

    /**
     * «جرّب إشعاراً الآن» في تطبيقي المندوب والكول سنتر (docs/plan/93): إلى هواتف هذا الحساب وحده، ويقول أين
     * يقف الإرسال — كما في تطبيق التاجر (docs/plan/89).
     */
    public function test(Request $request): JsonResponse
    {
        $devices = PushDevice::query()->where('user_id', $request->user()->id)->pluck('token')->all();
        $report = Fcm::deliver($devices, 'تجربة الإشعارات', 'وصل هذا الإشعار إلى هاتفك: الإشعارات تعمل.', ['kind' => 'test'], StaffPush::CHANNEL);

        if ($report['dead'] !== []) {
            PushDevice::query()->whereIn('token', $report['dead'])->delete();
        }

        $message = match ($report['error']) {
            null => 'أُرسل إشعارٌ تجريبيّ إلى هاتفك. إن لم يظهر في شريط الهاتف فالإشعارات مطفأةٌ للتطبيق في إعدادات الهاتف.',
            'no_key' => 'الخادم غير مهيّأ لإشعارات الهاتف بعد: مفتاح Firebase غير موجود على الخادم.',
            'no_device' => 'هاتفك غير مسجّل للإشعارات. اسمح للتطبيق بالإشعارات ثم أعد فتحه.',
            'auth' => 'لم يقبل Firebase مفتاح الخادم.',
            'expired' => 'رمز هاتفك قديم وحُذف. أعد فتح التطبيق ليُسجَّل من جديد.',
            default => 'رفض Firebase الإرسال إلى هاتفك.',
        };

        return response()->json(['ok' => $report['error'] === null, 'message' => $message, 'sent' => $report['sent'],
            'server' => Fcm::enabled(), 'devices' => count($devices)], $report['error'] === null ? 200 : 422);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        PushDevice::query()->where('user_id', $request->user()->id)->where('token', $data['token'])->delete();

        return response()->json(['message' => 'أُلغي تسجيل الهاتف.']);
    }
}
