<?php

namespace App\Support\Permissions;

use App\Support\Permissions\Ability as A;

/**
 * قوالب المراتب: أسماء المراتب كما ظهرت في النظام الذي تعمل عليه الشركات
 * اليوم (docs/plan/17 §٥)، وما تفتحه كلٌّ منها من صلاحياتنا.
 *
 * بدايةٌ لا قيد: الشركة تُنشئ المرتبة من القالب ثم تغيّر اسمها ومربّعاتها.
 * ولا يُحفظ القالب في المرتبة إلّا اسمه — تعديل القالب هنا لا يمسّ مرتبةً
 * أُنشئت منه.
 */
final class RankTemplates
{
    /**
     * @return array<string, array{name: string, hint: string, abilities: list<string>}>
     */
    public static function all(): array
    {
        $shipments = [A::SHIPMENTS_VIEW, A::SHIPMENTS_CREATE, A::SHIPMENTS_EDIT, A::SHIPMENTS_STATUS, A::SHIPMENTS_ASSIGN];
        $floor = [A::TRANSPORT_MANAGE, A::PICKUPS_MANAGE, A::RETURNS_MANAGE];
        $money = [A::MONEY_VIEW, A::MONEY_CASH, A::MONEY_EXPENSES, A::MONEY_SETTLE, A::MONEY_PAY, A::MONEY_CONFIRM_AMOUNT];

        $templates = [
            'system_admin' => [
                'name' => 'مدير نظام',
                'hint' => 'كل شيء.',
                'abilities' => A::all(),
            ],
            'branch_manager' => [
                'name' => 'مدير فرع',
                'hint' => 'العمليات كلّها، ويرى المال ولا يحرّكه، والتقارير المالية.',
                'abilities' => [...$shipments, ...$floor, A::NOTIFY_SEND, A::SUPPORT_REPLY, A::CONTROL_DUPLICATES,
                    A::CONTROL_FORCE, A::MONEY_VIEW, A::REPORTS_VIEW, A::REPORTS_FINANCIAL,
                    A::SETTINGS_MERCHANTS, A::SETTINGS_COURIERS, A::SETTINGS_ZONES],
            ],
            'chief_accountant' => [
                'name' => 'محاسب رئيسي',
                'hint' => 'المال كلّه والعمليات والتقارير، وإعداد التجّار والمندوبين والمناطق والتسعير.',
                'abilities' => [...$shipments, ...$floor, ...$money, A::REPORTS_VIEW, A::CONTROL_DUPLICATES,
                    A::SETTINGS_MERCHANTS, A::SETTINGS_COURIERS, A::SETTINGS_ZONES, A::SETTINGS_PRICING],
            ],
            'general_accountant' => [
                'name' => 'محاسب عام',
                'hint' => 'الصناديق والتسويات والمصروفات، والتقارير المالية والموقف المالي.',
                'abilities' => [A::SHIPMENTS_VIEW, A::MONEY_VIEW, A::MONEY_CASH, A::MONEY_EXPENSES, A::MONEY_SETTLE,
                    A::REPORTS_VIEW, A::REPORTS_FINANCIAL],
            ],
            'accountant' => [
                'name' => 'محاسب',
                'hint' => 'الصناديق وتسوية المندوبين والراجع، وتقارير العمل.',
                'abilities' => [A::SHIPMENTS_VIEW, A::RETURNS_MANAGE, A::MONEY_VIEW, A::MONEY_CASH, A::MONEY_SETTLE,
                    A::REPORTS_VIEW],
            ],
            'follow_up' => [
                'name' => 'متابعة',
                'hint' => 'يتابع الشحنات المتعثّرة ويغيّر حالتها ويجيب التجّار.',
                'abilities' => [A::SHIPMENTS_VIEW, A::SHIPMENTS_STATUS, A::SUPPORT_REPLY, A::CONTROL_DUPLICATES],
            ],
            'returns_clerk' => [
                'name' => 'موظّف رواجع',
                'hint' => 'تصفيات الراجع، والأكياس والكشوف، وطلبات الاستلام ومندوبوه.',
                'abilities' => [A::SHIPMENTS_VIEW, A::RETURNS_MANAGE, A::TRANSPORT_MANAGE, A::PICKUPS_MANAGE,
                    A::SETTINGS_COURIERS],
            ],
            'entry_update' => [
                'name' => 'موظّف إدخال وتحديث طلبات',
                'hint' => 'يُنشئ الشحنات ويصحّحها ويسندها، والتجّار والمندوبون ومناطقهم.',
                'abilities' => [A::SHIPMENTS_VIEW, A::SHIPMENTS_CREATE, A::SHIPMENTS_EDIT, A::SHIPMENTS_ASSIGN,
                    A::TRANSPORT_MANAGE, A::SETTINGS_MERCHANTS, A::SETTINGS_COURIERS, A::SETTINGS_ZONES],
            ],
            'entry_warehouse' => [
                'name' => 'موظّف إدخال ومخزن',
                'hint' => 'الإدخال والمخزن: الشحنات وحالاتها وإسنادها، والأكياس وطلبات الاستلام.',
                'abilities' => [A::SHIPMENTS_VIEW, A::SHIPMENTS_CREATE, A::SHIPMENTS_STATUS, A::SHIPMENTS_ASSIGN,
                    A::TRANSPORT_MANAGE, A::PICKUPS_MANAGE],
            ],
            'entry_warehouse_lead' => [
                'name' => 'مسؤول إدخال ومخزن',
                'hint' => 'ما لموظّف الإدخال والمخزن، ومعه التصحيح والراجع والإشعارات.',
                'abilities' => [...$shipments, ...$floor, A::NOTIFY_SEND],
            ],
            'entry_warehouse_trips' => [
                'name' => 'موظّف إدخال ومخزن خاص مشاوير',
                'hint' => 'مراحل النقل وطلبات الشحن.',
                'abilities' => [A::SHIPMENTS_VIEW, A::SHIPMENTS_CREATE, A::SHIPMENTS_STATUS, A::TRANSPORT_MANAGE,
                    A::PICKUPS_MANAGE],
            ],
            'pickup_desk' => [
                'name' => 'موظّف مندوب استلام',
                'hint' => 'طلبات الاستلام وشحنات التجّار وحساباتهم.',
                'abilities' => [A::SHIPMENTS_VIEW, A::SHIPMENTS_CREATE, A::PICKUPS_MANAGE, A::MONEY_VIEW],
            ],
            'customer_service' => [
                'name' => 'خدمة العملاء',
                'hint' => 'تقرأ وتُنشئ وتُجيب، ولا تغيّر مصير شحنة ولا ديناراً.',
                'abilities' => [A::SHIPMENTS_VIEW, A::SHIPMENTS_CREATE, A::REPORTS_VIEW, A::SUPPORT_REPLY],
            ],
        ];

        return array_map(fn (array $t) => ['abilities' => A::ordered($t['abilities'])] + $t, $templates);
    }

    /** @return array{name: string, hint: string, abilities: list<string>}|null */
    public static function find(?string $key): ?array
    {
        return $key === null ? null : (static::all()[$key] ?? null);
    }
}
