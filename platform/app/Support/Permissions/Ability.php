<?php

namespace App\Support\Permissions;

use App\Enums\UserRole;

/**
 * الصلاحيات.
 *
 * كانت الأدوار أسماءً بلا أثر: «خدمة العملاء» و«محاسب» و«صاحب الشركة»
 * يفتحون الشاشات نفسها ويفعلون الشيء نفسه — فموظّف استقبال يستطيع دفع
 * كشف تاجر وتعديل جرد القاصة. الدور بلا صلاحيات لافتةٌ على الباب.
 *
 * والقائمة مغلقة عمداً: صلاحية تُضاف بالكود لا من الشاشة، حتى لا يصير
 * الجدول مفتوحاً على أسماء لا يحرسها شيء.
 */
class Ability
{
    // العمليات
    public const SHIPMENTS_VIEW = 'shipments.view';

    public const SHIPMENTS_CREATE = 'shipments.create';

    public const SHIPMENTS_STATUS = 'shipments.status';

    public const SHIPMENTS_ASSIGN = 'shipments.assign';

    public const PICKUPS_MANAGE = 'pickups.manage';

    public const RETURNS_MANAGE = 'returns.manage';

    public const TRANSPORT_MANAGE = 'transport.manage';

    // المال
    public const MONEY_VIEW = 'money.view';

    public const MONEY_SETTLE = 'money.settle';

    public const MONEY_PAY = 'money.pay';

    public const MONEY_CASH = 'money.cash';

    public const MONEY_EXPENSES = 'money.expenses';

    public const MONEY_CONFIRM_AMOUNT = 'money.confirm_amount';

    // الرقابة
    public const CONTROL_FORCE = 'control.force';

    public const CONTROL_DUPLICATES = 'control.duplicates';

    // الإعدادات
    public const SETTINGS_PEOPLE = 'settings.people';

    public const SETTINGS_BRANCHES = 'settings.branches';

    public const SETTINGS_PRICING = 'settings.pricing';

    public const SETTINGS_ZONES = 'settings.zones';

    public const SETTINGS_PERMISSIONS = 'settings.permissions';

    public const REPORTS_VIEW = 'reports.view';

    /** @return array<string, array{label: string, abilities: array<string, string>}> */
    public static function groups(): array
    {
        return [
            'operations' => ['label' => 'العمليات', 'abilities' => [
                self::SHIPMENTS_VIEW   => 'عرض الشحنات',
                self::SHIPMENTS_CREATE => 'إنشاء شحنة ورفع ملف',
                self::SHIPMENTS_STATUS => 'تغيير حالة شحنة',
                self::SHIPMENTS_ASSIGN => 'إسناد للمندوبين',
                self::PICKUPS_MANAGE   => 'طلبات الاستلام',
                self::RETURNS_MANAGE   => 'الراجع: استلاماً وتسليماً',
                self::TRANSPORT_MANAGE => 'الأكياس وكشوف النقل',
            ]],
            'money' => ['label' => 'المال', 'abilities' => [
                self::MONEY_VIEW           => 'عرض الحسابات والأرصدة',
                self::MONEY_SETTLE         => 'تسوية المندوبين',
                self::MONEY_PAY            => 'دفع كشوف التجّار',
                self::MONEY_CASH           => 'القاصة والجرد والمناقلة',
                self::MONEY_EXPENSES       => 'المصروفات',
                self::MONEY_CONFIRM_AMOUNT => 'تأكيد مبلغ الوصل (لا رجعة)',
            ]],
            'control' => ['label' => 'الرقابة', 'abilities' => [
                self::CONTROL_FORCE      => 'التغيير الإجباري خارج المسار',
                self::CONTROL_DUPLICATES => 'حسم الشحنات المكرّرة',
            ]],
            'settings' => ['label' => 'الإعدادات والتقارير', 'abilities' => [
                self::REPORTS_VIEW          => 'التقارير',
                self::SETTINGS_PEOPLE       => 'المستخدمون والتجّار والمندوبون',
                self::SETTINGS_BRANCHES     => 'الفروع والمراكز',
                self::SETTINGS_PRICING      => 'التسعيرات',
                self::SETTINGS_ZONES        => 'المناطق',
                self::SETTINGS_PERMISSIONS  => 'الصلاحيات',
            ]],
        ];
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return collect(static::groups())
            ->flatMap(fn (array $group) => array_keys($group['abilities']))
            ->values()
            ->all();
    }

    public static function label(string $ability): string
    {
        foreach (static::groups() as $group) {
            if (isset($group['abilities'][$ability])) {
                return $group['abilities'][$ability];
            }
        }

        return $ability;
    }

    /**
     * الافتراضي لكل دور — ما يفعله صاحب الدور عادةً، لا ما قد يحتاجه يوماً.
     *
     * @return array<int, string>
     */
    public static function defaultsFor(UserRole $role): array
    {
        $operations = [
            self::SHIPMENTS_VIEW, self::SHIPMENTS_CREATE, self::SHIPMENTS_STATUS,
            self::SHIPMENTS_ASSIGN, self::PICKUPS_MANAGE, self::RETURNS_MANAGE,
            self::TRANSPORT_MANAGE,
        ];

        $money = [
            self::MONEY_VIEW, self::MONEY_SETTLE, self::MONEY_PAY,
            self::MONEY_CASH, self::MONEY_EXPENSES, self::MONEY_CONFIRM_AMOUNT,
        ];

        return match ($role) {
            UserRole::CompanyOwner, UserRole::CompanyAdmin => static::all(),

            // مدير الفرع يُدير العمليات ويرى المال ولا يُحرّكه
            UserRole::BranchManager => [
                ...$operations, self::MONEY_VIEW, self::REPORTS_VIEW,
                self::CONTROL_DUPLICATES, self::SETTINGS_ZONES,
            ],

            UserRole::Operations => [...$operations, self::REPORTS_VIEW],

            // خدمة العملاء تقرأ وتُنشئ ولا تُغيّر مصير شحنة ولا ديناراً
            UserRole::CustomerService => [
                self::SHIPMENTS_VIEW, self::SHIPMENTS_CREATE, self::REPORTS_VIEW,
            ],

            UserRole::Accountant => [
                self::SHIPMENTS_VIEW, ...$money, self::REPORTS_VIEW,
                self::CONTROL_DUPLICATES, self::MONEY_CONFIRM_AMOUNT,
            ],

            default => [],
        };
    }
}
