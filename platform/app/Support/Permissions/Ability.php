<?php

namespace App\Support\Permissions;

use App\Enums\UserRole;
use App\Support\StaffNavigation;

/**
 * الصلاحيات.
 *
 * كانت الأدوار أسماءً بلا أثر: «خدمة العملاء» و«محاسب» و«صاحب الشركة»
 * يفتحون الشاشات نفسها ويفعلون الشيء نفسه — فموظّف استقبال يستطيع دفع
 * كشف تاجر وتعديل جرد القاصة. الدور بلا صلاحيات لافتةٌ على الباب.
 *
 * والقائمة مغلقة عمداً: صلاحية تُضاف بالكود لا من الشاشة، حتى لا يصير
 * الجدول مفتوحاً على أسماء لا يحرسها شيء. وتُعرض مجمّعةً بقوائم الشريط
 * كما يراها الموظّف — «اسم القائمة» ثم ما تفتحه منها (docs/plan/21 §١).
 */
class Ability
{
    // المتابعة
    /** إعلانٌ واحد يبلغ كل المناديب أو كل التجّار */
    public const NOTIFY_SEND = 'notify.send';

    // الشحنات
    public const SHIPMENTS_VIEW = 'shipments.view';

    public const SHIPMENTS_CREATE = 'shipments.create';

    /** تصحيح هاتفٍ أو عنوانٍ أو مبلغٍ قبل أن تُقفَل الشحنة — كل تعديلٍ في سجلّها */
    public const SHIPMENTS_EDIT = 'shipments.edit';

    public const SHIPMENTS_STATUS = 'shipments.status';

    public const SHIPMENTS_ASSIGN = 'shipments.assign';

    /**
     * القائمة كلّها بأرقام الزبائن في ملف: الأرقام في الشاشة مخفيّة تُكشف واحداً
     * واحداً، فالتصدير صلاحيةٌ بعينها وكل تصديرٍ في سجلّ التدقيق.
     */
    public const SHIPMENTS_EXPORT = 'shipments.export';

    /** مسح ما أُنشئ خطأً قبل أن يصلنا، واسترجاعه — «صلاحية تعديل وحذف الشحنات» في المعتاد */
    public const SHIPMENTS_DELETE = 'shipments.delete';

    /**
     * تعديل أجور الشحنة (التاجر والمندوب) وطلبيتها أيّاً كانت حالها، ولو انتهت أو كان فيها
     * مشكلة — والفرق قيدٌ في الحساب بسببه (docs/plan/38). لصاحب الشركة وللمحاسب افتراضاً
     * (docs/plan/53)، ويُمنح لمن يشاء بمرتبةٍ أو صلاحيةٍ استثنائية. وبه وحده تُكتب أجور
     * التاجر في نموذج الشحنة وتعديلها.
     */
    public const SHIPMENTS_OVERRIDE = 'shipments.override';

    /**
     * سطر «إجمالي المبالغ» و«إجمالي التوصيل» تحت قائمة الشحنات لما في البحث كلّه (docs/plan/60):
     * للفرع والرئيسي والمحاسب افتراضاً، ولمن يُمنح بمرتبةٍ أو صلاحيةٍ استثنائية.
     */
    public const SHIPMENTS_TOTALS = 'shipments.totals';

    /** ما لا يُعطى بالدور: لصاحب الشركة، ولمن يُمنح بعينه */
    public const OWNER_ONLY = [self::SHIPMENTS_OVERRIDE];

    // التوصيل · الراجع
    public const TRANSPORT_MANAGE = 'transport.manage';

    public const PICKUPS_MANAGE = 'pickups.manage';

    public const RETURNS_MANAGE = 'returns.manage';

    // المال والحسابات
    public const MONEY_CASH = 'money.cash';

    public const MONEY_VIEW = 'money.view';

    public const MONEY_EXPENSES = 'money.expenses';

    public const MONEY_SETTLE = 'money.settle';

    public const MONEY_PAY = 'money.pay';

    public const MONEY_CONFIRM_AMOUNT = 'money.confirm_amount';

    // التقارير
    public const REPORTS_VIEW = 'reports.view';

    /** أرباح الشركة ومال رواجعها: للمدير والمحاسب، لا لكل من يقرأ تقريراً */
    public const REPORTS_FINANCIAL = 'reports.financial';

    // المتابعة
    /** الردّ على محادثات التجّار */
    public const SUPPORT_REPLY = 'support.reply';

    /** طلبات المناديب لتغيير المبلغ عند الباب: اعتمادها أو رفضها (docs/plan/30) */
    public const TICKETS_HANDLE = 'tickets.handle';

    public const CONTROL_DUPLICATES = 'control.duplicates';

    public const CONTROL_FORCE = 'control.force';

    /** إجازة شحنات التاجر المعلَّق للمراجعة لتخرج مع المندوب */
    public const CONTROL_REVIEW = 'control.review';

    // الإعدادات
    public const SETTINGS_MERCHANTS = 'settings.merchants';

    public const SETTINGS_COURIERS = 'settings.couriers';

    /** موظّفو الشركة وحساباتهم */
    public const SETTINGS_USERS = 'settings.users';

    public const SETTINGS_PERMISSIONS = 'settings.permissions';

    public const SETTINGS_BRANCHES = 'settings.branches';

    public const SETTINGS_ZONES = 'settings.zones';

    public const SETTINGS_PRICING = 'settings.pricing';

    /** هاتف الشركة وواتساب الدعم ولونها */
    public const SETTINGS_COMPANY = 'settings.company';

    /**
     * ما يسري على الشركة كلّها لا على فرعٍ بعينه: المراتب والفروع والتسعيرات
     * وبيانات الشركة. لصاحب الشركة ومديرها وموظّفي الفرع الرئيسي؛ وموظّف فرعٍ
     * آخر لا يملكه ولو كان في مرتبته — كل فرعٍ يُعدّه صاحب الشركة ويختار تسعيرته.
     *
     * والموظّفون ليسوا منها: الفرع يُضيف موظّفيه بنفسه، في فرعه وحده وبأدوارٍ
     * لا تعلو دوره (UserController).
     */
    public const COMPANY_WIDE = [
        self::SETTINGS_PERMISSIONS, self::SETTINGS_BRANCHES,
        self::SETTINGS_PRICING, self::SETTINGS_COMPANY,
    ];

    /** @var array<int, string>|null */
    private static ?array $all = null;

    /**
     * الصلاحيات بقوائمها، بترتيب الشريط. والقائمة هنا اسمها في الشريط نفسه
     * (StaffNavigation)، فتُقرأ «المرتبة» كما يُقرأ الشريط.
     *
     * @return array<string, array{label: string, abilities: array<string, string>}>
     */
    public static function groups(): array
    {
        return [
            'shipments' => ['label' => 'الشحنات', 'abilities' => [
                self::SHIPMENTS_VIEW => 'عرض الشحنات',
                self::SHIPMENTS_CREATE => 'إنشاء شحنة ورفع ملف',
                self::SHIPMENTS_EDIT => 'تعديل بيانات الشحنة',
                self::SHIPMENTS_STATUS => 'تغيير حالة شحنة',
                self::SHIPMENTS_ASSIGN => 'إسناد للمندوبين',
                self::SHIPMENTS_EXPORT => 'تصدير القوائم (Excel وPDF) بأرقام الزبائن',
                self::SHIPMENTS_TOTALS => 'إجمالي المبالغ والتوصيل تحت قائمة الشحنات',
                self::SHIPMENTS_DELETE => 'مسح الشحنات قبل استلامها، واسترجاعها',
                self::SHIPMENTS_OVERRIDE => 'تعديل أجور التاجر والمندوب والطلبية لأيّ شحنة، ولو انتهت',
            ]],
            'delivery' => ['label' => 'التوصيل', 'abilities' => [
                self::PICKUPS_MANAGE => 'طلبات الاستلام',
                self::TRANSPORT_MANAGE => 'كشوف المناديب والأكياس والنقل بين الفروع',
            ]],
            'returns' => ['label' => 'الراجع', 'abilities' => [
                self::RETURNS_MANAGE => 'الراجع: استلاماً وفرزاً وتسليماً',
            ]],
            'money' => ['label' => 'المال والحسابات', 'abilities' => [
                self::MONEY_VIEW => 'عرض المال والحسابات والأرصدة',
                self::MONEY_CASH => 'الصندوق والجرد والمناقلة',
                self::MONEY_EXPENSES => 'المصروفات',
                self::MONEY_SETTLE => 'محاسبة المندوبين ومندوبي الاستلام',
                self::MONEY_PAY => 'دفع كشوف التجّار',
                self::MONEY_CONFIRM_AMOUNT => 'تأكيد مبلغ الوصل (لا رجعة)',
            ]],
            'reports' => ['label' => 'التقارير', 'abilities' => [
                self::REPORTS_VIEW => 'التقارير',
                self::REPORTS_FINANCIAL => 'أرباح الشحنات ومال الرواجع',
            ]],
            'review' => ['label' => 'المتابعة', 'abilities' => [
                self::SUPPORT_REPLY => 'محادثات التجّار',
                self::TICKETS_HANDLE => 'طلبات المناديب لتغيير المبلغ (اعتمادٌ يغيّر مبلغ الشحنة)',
                self::NOTIFY_SEND => 'الإشعارات وإعلانات التطبيق',
                self::CONTROL_REVIEW => 'إجازة الشحنات المعلّقة للمراجعة',
                self::CONTROL_DUPLICATES => 'حسم الشحنات المكرّرة',
                self::CONTROL_FORCE => 'التغيير الإجباري خارج المسار',
            ]],
            'settings' => ['label' => 'الإعدادات', 'abilities' => [
                self::SETTINGS_MERCHANTS => 'التجّار',
                self::SETTINGS_COURIERS => 'المندوبون',
                self::SETTINGS_USERS => 'المستخدمون',
                self::SETTINGS_PERMISSIONS => 'الصلاحيات والمراتب',
                self::SETTINGS_BRANCHES => 'الفروع والمراكز',
                self::SETTINGS_ZONES => 'المناطق',
                self::SETTINGS_PRICING => 'التسعيرات',
                self::SETTINGS_COMPANY => 'بيانات الشركة وواتساب الدعم',
            ]],
        ];
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        // يُسأل عنها في كل فحص صلاحية، وكل رابطٍ في الشريط فحص
        return self::$all ??= collect(static::groups())
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

    /** اسم القائمة التي تقع فيها الصلاحية. */
    public static function menuOf(string $ability): ?string
    {
        foreach (static::groups() as $group) {
            if (isset($group['abilities'][$ability])) {
                return $group['label'];
            }
        }

        return null;
    }

    /**
     * ما تفتحه كل صلاحية من شاشات الشريط — «القوائم الفرعية» تحت كل صلاحية
     * في شاشة المرتبة. من الشريط نفسه لا من قائمةٍ ثانية تُنسى.
     *
     * @return array<string, list<string>>
     */
    public static function screens(): array
    {
        $screens = [];

        foreach (StaffNavigation::menus() as [, , $links]) {
            foreach ($links as $link) {
                if ($link[3] !== null) {
                    $screens[$link[3]][] = $link[1];
                }
            }
        }

        return array_map(fn (array $labels) => array_values(array_unique($labels)), $screens);
    }

    /**
     * الافتراضي لكل دور — ما يفعله صاحب الدور عادةً، لا ما قد يحتاجه يوماً.
     * والمرتبة إن أُسندت تحلّ محلّه.
     *
     * @return array<int, string>
     */
    public static function defaultsFor(UserRole $role): array
    {
        $operations = [
            self::SHIPMENTS_VIEW, self::SHIPMENTS_CREATE, self::SHIPMENTS_EDIT, self::SHIPMENTS_STATUS,
            self::SHIPMENTS_ASSIGN, self::PICKUPS_MANAGE, self::RETURNS_MANAGE,
            self::TRANSPORT_MANAGE,
        ];

        $money = [
            self::MONEY_VIEW, self::MONEY_SETTLE, self::MONEY_PAY,
            self::MONEY_CASH, self::MONEY_EXPENSES, self::MONEY_CONFIRM_AMOUNT,
        ];

        $abilities = match ($role) {
            // وصاحب الفرع مثلهما في فرعه: ما يسري على الشركة كلّها يُنزَع منه (COMPANY_WIDE)
            UserRole::CompanyOwner => static::all(),
            UserRole::CompanyAdmin, UserRole::BranchOwner => array_diff(static::all(), self::OWNER_ONLY),

            // مدير الفرع يُدير العمليات ويرى المال ولا يُحرّكه؛ وله التقارير المالية كما في المعتاد
            UserRole::BranchManager => [
                ...$operations, self::SHIPMENTS_EXPORT, self::SHIPMENTS_TOTALS, self::MONEY_VIEW, self::REPORTS_VIEW, self::REPORTS_FINANCIAL,
                self::CONTROL_DUPLICATES, self::CONTROL_REVIEW, self::SETTINGS_ZONES, self::NOTIFY_SEND,
                self::SUPPORT_REPLY, self::TICKETS_HANDLE,
            ],

            // العمليات تُبلغ المناديب كل صباح: «ابدأوا السابعة»، «الطريق مغلق»
            UserRole::Operations => [...$operations, self::REPORTS_VIEW, self::NOTIFY_SEND, self::SUPPORT_REPLY, self::TICKETS_HANDLE],

            // الكول سنتر يتابع الشحنة: يصحّح بياناتها ويحدّث حالتها ويعالج ما لم يُسلَّم وطلبات
            // المناديب لتغيير المبلغ (docs/plan/30) — ولا يحرّك ديناراً في الحسابات
            UserRole::CustomerService => [
                self::SHIPMENTS_VIEW, self::SHIPMENTS_CREATE, self::SHIPMENTS_EDIT, self::SHIPMENTS_STATUS,
                self::REPORTS_VIEW, self::SUPPORT_REPLY, self::TICKETS_HANDLE,
            ],

            // والمحاسب يعدّل أجور التاجر والمندوب (docs/plan/53) — وغيره بمرتبةٍ أو صلاحيةٍ استثنائية
            UserRole::Accountant => [
                self::SHIPMENTS_VIEW, self::SHIPMENTS_EXPORT, self::SHIPMENTS_TOTALS, ...$money, self::REPORTS_VIEW, self::REPORTS_FINANCIAL,
                self::CONTROL_DUPLICATES, self::SHIPMENTS_OVERRIDE,
            ],

            default => [],
        };

        return static::ordered($abilities);
    }

    /**
     * بترتيب القائمة وبلا تكرار، وما ليس صلاحيةً يسقط — العمود JSON قد يحمل
     * اسماً حُذف أو كُتب خطأً.
     *
     * @param  iterable<string>  $abilities
     * @return array<int, string>
     */
    public static function ordered(iterable $abilities): array
    {
        $given = is_array($abilities) ? $abilities : iterator_to_array($abilities, false);

        return array_values(array_intersect(static::all(), $given));
    }
}
