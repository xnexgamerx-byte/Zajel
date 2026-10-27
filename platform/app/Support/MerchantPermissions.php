<?php

namespace App\Support;

use App\Enums\Feature;
use App\Enums\UserRole;
use App\Models\User;

/**
 * صلاحيات موظّفي التاجر (docs/plan/87). صاحب المتجر (merchant_permissions فارغة) يفتح كلّ شيء، ومنه
 * إدارة موظّفيه. والموظّف ما فُتح له وحده: الخادم يرفض غيره، والتطبيق لا يعرضه.
 */
final class MerchantPermissions
{
    /** إدارة الموظّفين وشعار المتجر: لصاحبه وحده، ولا تُعطى لموظّف */
    public const OWNER = 'owner';

    public const ALL = [
        'shipments' => ['عرض الشحنات', 'قوائم الشحنات وتتبّعها وتقويمها، والبحث فيها'],
        'create' => ['إدخال الشحنات', 'شحنةٌ جديدة باليد أو من صورة أو بالصوت، ومن ملف Excel'],
        'print' => ['الطابعة', 'طباعة وصولات الشحنات الجديدة'],
        'pickups' => ['طلب مندوب', 'طلب مندوبٍ يستلم الشحنات'],
        'processing' => ['المعالجة', 'قرار الشحنات التي لم تُسلَّم: إعادة توصيل، تأجيل، إرجاع'],
        'finance' => ['المالية', 'الرصيد والكشوفات، وطلب الحساب، واستلام الحساب والراجع'],
        'support' => ['الدعم', 'المحادثات مع الشركة'],
    ];

    /** أدوارٌ جاهزة، وما سواها «مخصّص» يختاره صاحب المتجر */
    public const ROLES = [
        'entry' => ['موظّف إدخال', ['create', 'print', 'pickups', 'shipments']],
        'accountant' => ['محاسب', ['finance', 'shipments']],
        'follow' => ['متابعة', ['shipments', 'processing', 'support']],
    ];

    public static function isOwner(User $user): bool
    {
        return $user->role === UserRole::Merchant && $user->merchant_permissions === null;
    }

    /** @return list<string> ما يفتحه الحساب، وصاحب المتجر كلّها ومعها OWNER */
    public static function of(User $user): array
    {
        if (self::isOwner($user)) {
            return [...array_keys(self::ALL), self::OWNER];
        }

        return array_values(array_intersect(array_keys(self::ALL), (array) $user->merchant_permissions));
    }

    public static function allows(User $user, string $permission): bool
    {
        return in_array($permission, self::of($user), true);
    }

    /** الموظّف يعمل ما دامت الميزة مفتوحةً لشركته، وصاحب المتجر دائماً */
    public static function active(User $user): bool
    {
        return self::isOwner($user) || FeatureGate::enabled(Feature::MerchantStaff);
    }

    /** اسم دور الموظّف: جاهزٌ إن طابق، وإلّا «مخصّص» */
    public static function role(array $permissions): string
    {
        sort($permissions);
        foreach (self::ROLES as $key => [, $set]) {
            sort($set);
            if ($set === $permissions) {
                return $key;
            }
        }

        return 'custom';
    }
}
