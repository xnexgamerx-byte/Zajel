<?php

namespace App\Support\Push;

use App\Models\PushDevice;
use App\Support\MerchantPermissions;
use Illuminate\Support\Facades\Log;

/**
 * إشعار هواتف التاجر: كلّ هاتفٍ دخل منه أحدُ مستخدميه (docs/plan/82) — بما اختاره صاحب الهاتف (docs/plan/86):
 * النوع الذي أطفأه لا يصل هاتفه، والصوت بقناته.
 */
final class MerchantPush
{
    public static function notify(int $merchantId, string $title, string $body, string $kind): void
    {
        try {
            $devices = PushDevice::query()
                ->whereHas('user', fn ($q) => $q->where('merchant_id', $merchantId))
                ->with('user:id,role,notify_prefs,merchant_permissions')
                ->get(['id', 'token', 'user_id'])
                // أخبار الشحنات لمن يراها من موظّفيه (docs/plan/87)
                ->filter(fn (PushDevice $d) => MerchantPermissions::allows($d->user, 'shipments') && NotifyPrefs::allows($d->user, $kind));

            $dead = [];
            foreach ($devices->groupBy(fn (PushDevice $d) => NotifyPrefs::channel($d->user)) as $channel => $group) {
                $dead = [...$dead, ...Fcm::send($group->pluck('token')->all(), $title, $body, ['kind' => $kind], (string) $channel)];
            }

            if ($dead !== []) {
                PushDevice::query()->whereIn('token', $dead)->delete();
            }
        } catch (\Throwable $e) {
            // الإشعار لا يوقف الحالة التي حدثت: الجرس يبقى يحمل الخبر
            Log::warning('Push: تعذّر إرسال الإشعار', ['error' => $e->getMessage()]);
        }
    }
}
