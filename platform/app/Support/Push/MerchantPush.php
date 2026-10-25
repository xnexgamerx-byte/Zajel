<?php

namespace App\Support\Push;

use App\Models\PushDevice;
use Illuminate\Support\Facades\Log;

/** إشعار هواتف التاجر: كلّ هاتفٍ دخل منه أحدُ مستخدميه (docs/plan/82). */
final class MerchantPush
{
    public static function notify(int $merchantId, string $title, string $body): void
    {
        try {
            $devices = PushDevice::query()
                ->whereHas('user', fn ($q) => $q->where('merchant_id', $merchantId))
                ->get(['id', 'token']);

            if ($devices->isEmpty()) {
                return;
            }

            $dead = Fcm::send($devices->pluck('token')->all(), $title, $body, ['kind' => 'merchant']);

            if ($dead !== []) {
                PushDevice::query()->whereIn('token', $dead)->delete();
            }
        } catch (\Throwable $e) {
            // الإشعار لا يوقف الحالة التي حدثت: الجرس يبقى يحمل الخبر
            Log::warning('Push: تعذّر إرسال الإشعار', ['error' => $e->getMessage()]);
        }
    }
}
