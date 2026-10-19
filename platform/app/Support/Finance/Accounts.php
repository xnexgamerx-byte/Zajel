<?php

namespace App\Support\Finance;

/**
 * دليل الحسابات (docs/plan/68 §٣): رمزٌ واسمٌ ونوعٌ لكل حساب.
 *
 * في الشيفرة لا في جدول: الحسابات تُضاف مع قواعد القيد التي تستعملها، ولا يُفتح
 * حسابٌ من الشاشة بلا قاعدةٍ تحرّكه. والأبعاد (الفرع، المندوب، التاجر، الشحنة،
 * الصندوق، بند المصروف) على سطر القيد لا في الحساب.
 */
final class Accounts
{
    public const CASH = '1100';

    public const CUSTODY = '1200';

    public const IN_TRANSIT = '1210';

    public const OUTSIDE_COURIER = '1290';

    public const SHIPMENT_CLEARING = '1900';

    public const CASH_BRIDGE = '1950';

    public const BOX_TRANSFERS = '1960';

    public const UNMAPPED = '1999';

    public const MERCHANTS = '2100';

    public const DEPOSITS = '2110';

    public const PREPAID = '2120';

    public const COMMISSIONS = '2200';

    public const EXPENSES_DUE = '2300';

    public const OPENING = '3100';

    public const DRAWINGS = '3300';

    public const DELIVERY_REVENUE = '4100';

    public const RETURN_REVENUE = '4110';

    public const OTHER_INCOME = '4200';

    public const COUNT_DIFFERENCES = '4210';

    public const COURIER_COMMISSIONS = '5100';

    public const PICKUP_SHARES = '5110';

    public const OPERATING_EXPENSES = '5200';

    /**
     * [الاسم، النوع، الجانب الطبيعي]. النوع: asset | liability | equity | revenue | expense | bridge.
     * الحساب الوسيط (bridge) يُصفَّر حين يكتمل طرفا العملية؛ وما بقي فيه سؤالٌ في المطابقة.
     *
     * @var array<string, array{string, string, string}>
     */
    public const ALL = [
        self::CASH               => ['النقد في الصناديق والبنوك', 'asset', 'debit'],
        self::CUSTODY            => ['نقد بعهدة المناديب', 'asset', 'debit'],
        self::IN_TRANSIT         => ['نقد بالطريق بين الفروع', 'asset', 'debit'],
        self::OUTSIDE_COURIER    => ['تحصيلٌ بلا مندوب مسجَّل', 'asset', 'debit'],
        self::SHIPMENT_CLEARING  => ['وسيط الشحنات', 'bridge', 'debit'],
        self::CASH_BRIDGE        => ['وسيط النقد', 'bridge', 'debit'],
        self::BOX_TRANSFERS      => ['مناقلات بين الصناديق', 'bridge', 'debit'],
        self::UNMAPPED           => ['حركات بلا قاعدة قيد', 'bridge', 'debit'],
        self::MERCHANTS          => ['حسابات التجّار (صافي)', 'liability', 'credit'],
        self::DEPOSITS           => ['تأمينات التجّار', 'liability', 'credit'],
        self::PREPAID            => ['أجور مقبوضة مقدّماً لم تُكتسب', 'liability', 'credit'],
        self::COMMISSIONS        => ['عمولات مستحقّة للمناديب', 'liability', 'credit'],
        self::EXPENSES_DUE       => ['مصروفات مستحقّة غير مدفوعة', 'liability', 'credit'],
        self::OPENING            => ['رأس المال والأرصدة الافتتاحية', 'equity', 'credit'],
        self::DRAWINGS           => ['مسحوبات الأرباح', 'equity', 'debit'],
        self::DELIVERY_REVENUE   => ['إيراد أجور التوصيل', 'revenue', 'credit'],
        self::RETURN_REVENUE     => ['إيراد أجور الراجع', 'revenue', 'credit'],
        self::OTHER_INCOME       => ['إيرادات أخرى (خصومات المناديب)', 'revenue', 'credit'],
        self::COUNT_DIFFERENCES  => ['فروقات جرد الصناديق (صافي)', 'revenue', 'credit'],
        self::COURIER_COMMISSIONS => ['عمولات المناديب', 'expense', 'debit'],
        self::PICKUP_SHARES      => ['حصص مندوبي الاستلام', 'expense', 'debit'],
        self::OPERATING_EXPENSES => ['مصروفات تشغيلية', 'expense', 'debit'],
    ];

    public const TYPES = [
        'asset'     => 'أصول',
        'liability' => 'التزامات',
        'equity'    => 'حقوق الملكية',
        'revenue'   => 'إيرادات',
        'expense'   => 'تكاليف',
        'bridge'    => 'حسابات وسيطة',
    ];

    public static function name(string $code): string
    {
        return self::ALL[$code][0] ?? $code;
    }

    public static function type(string $code): string
    {
        return self::ALL[$code][1] ?? 'bridge';
    }

    /** الرصيد بإشارة الحساب الطبيعية: موجبٌ حين يكون في جانبه المعتاد */
    public static function natural(string $code, int $debit, int $credit): int
    {
        return (self::ALL[$code][2] ?? 'debit') === 'debit' ? $debit - $credit : $credit - $debit;
    }
}
