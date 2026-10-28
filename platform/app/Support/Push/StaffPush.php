<?php

namespace App\Support\Push;

use App\Enums\UserRole;
use App\Http\Controllers\Api\AuthController;
use App\Models\Courier;
use App\Models\PushDevice;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * إشعارات الهاتف لتطبيقي المندوب والكول سنتر (docs/plan/90)، على الإرسال نفسه لتطبيق التاجر.
 *
 * - المندوب: شحناتٌ أُسندت إليه للتوصيل، ورسالةٌ من الكول سنتر.
 * - الكول سنتر: تذكرةُ مبلغٍ يفتحها مندوب، ورسالةٌ من تاجرٍ أو من مندوب.
 *
 * الإرسال بعد حفظ الحركة (DB::afterCommit)، فلا يصل إشعارٌ عن شيءٍ لم يُحفظ. وكلّ الفشل يُسجَّل ولا يوقف الحركة.
 */
final class StaffPush
{
    /** قناة «تحديثات الشحنات» في التطبيقين: بصوتٍ أعلى الشاشة (انظر MainActivity) */
    public const CHANNEL = 'updates';

    /** @param  Collection<int, User>  $users */
    public static function to(Collection $users, string $title, string $body, string $kind): void
    {
        try {
            $tokens = PushDevice::query()->whereIn('user_id', $users->pluck('id'))->pluck('token')
                ->unique()->values()->all();

            if ($tokens === []) {
                return;
            }

            $report = Fcm::deliver($tokens, $title, $body, ['kind' => $kind], self::CHANNEL);

            if ($report['dead'] !== []) {
                PushDevice::query()->whereIn('token', $report['dead'])->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('Push: تعذّر إرسال إشعار التطبيق', ['error' => $e->getMessage()]);
        }
    }

    /** حسابات المندوب نفسه */
    public static function courier(Courier $courier, string $title, string $body, string $kind): void
    {
        self::to(
            User::query()->where('role', UserRole::Courier->value)->where('courier_id', $courier->id)
                ->where('is_active', true)->get(),
            $title,
            $body,
            $kind,
        );
    }

    /** موظّفو الكول سنتر الذين يعالجون التذاكر أو الشحنات أو المحادثات — كما يدخلهم التطبيق */
    public static function callCentre(string $title, string $body, string $kind): void
    {
        $roles = array_map(fn (UserRole $r) => $r->value, AuthController::APPS['callcenter']);
        $abilities = AuthController::CALL_CENTRE_ABILITIES;

        self::to(
            User::query()->whereIn('role', $roles)->where('is_active', true)->get()
                ->filter(fn (User $u) => collect($abilities)->contains(fn ($a) => $u->can($a)))
                ->values(),
            $title,
            $body,
            $kind,
        );
    }
}
