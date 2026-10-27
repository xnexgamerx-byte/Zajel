<?php

namespace App\Support\Push;

use App\Models\User;

/**
 * ما يختاره التاجر لإشعارات هاتفه (docs/plan/86): أيّ الأنواع تصل هاتفه، وبصوتٍ أم بلا صوت. الجرس في التطبيق
 * والبوابة يحمل كلّ شيءٍ كما هو؛ هذا للهاتف وحده. وما لم يختره بعدُ شغّالٌ بصوت.
 */
final class NotifyPrefs
{
    /** الأنواع بأسمائها كما تُعرض للتاجر */
    public const KINDS = [
        'delivered' => 'وصلت شحنتك',
        'failed' => 'للمعالجة',
        'processed' => 'تمت معالجتها',
        'amount' => 'تغيّر مبلغ الوصل',
    ];

    /** قناتا أندرويد: تُنشأان في التطبيق (MainActivity)، وبهما يُعرف الصوت */
    public const CHANNEL_SOUND = 'updates';

    public const CHANNEL_SILENT = 'updates_silent';

    /** @return array<string, bool> الأنواع والصوت */
    public static function of(?User $user): array
    {
        $own = (array) ($user?->notify_prefs ?? []);

        return collect(array_keys(self::KINDS))->push('sound')
            ->mapWithKeys(fn ($key) => [$key => (bool) ($own[$key] ?? true)])->all();
    }

    public static function allows(?User $user, string $kind): bool
    {
        return self::of($user)[$kind] ?? true;
    }

    public static function channel(?User $user): string
    {
        return self::of($user)['sound'] ? self::CHANNEL_SOUND : self::CHANNEL_SILENT;
    }
}
