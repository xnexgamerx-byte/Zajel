<?php

namespace App\Support;

use App\Models\Rank;
use App\Models\User;

/**
 * «لوحة اليوم» كما يريدها صاحبها: اختصاراتٌ إلى شاشاته، والأقسام التي تهمّه، وقوائم
 * التنبيهات التي يتابعها. المحاسب يضع الصندوق ومحاسبة المندوبين والتجّار أمامه، وموظّف
 * المتابعة يضع المعالجة والتذاكر.
 *
 * من يقرّر: تخصيص الموظّف نفسه، وإلّا ما وضعه صاحب الشركة لمرتبته، وإلّا اللوحة كاملةً
 * كما كانت بلا اختصارات. والاختصار لا يظهر لمن لا يفتح شاشته ولو اختارته مرتبته.
 *
 * الشكل المحفوظ: {sections: [..], alerts: [..], shortcuts: [..]}؛ الاختصار مسارٌ
 * ومعاملاته كما في القائمة («announcements.index?audience=merchants»).
 */
final class HomeLayout
{
    /** أقسام اللوحة بترتيب ظهورها: [العنوان، ما فيه] */
    public const SECTIONS = [
        'today'        => ['اليوم', 'سُلّمت اليوم، والشحنات الجديدة خلال سبعة أيام، وزرّ «شحنة جديدة»'],
        'where'        => ['أين الشحنات الآن', 'مع المندوبين، في المخزن والنقل، متعثّرة، قيد التنفيذ'],
        'money'        => ['المال المعلّق', 'لم تُحصَّل، ونقدٌ بيد المندوبين، ومستحقّ للتجّار — وعمر أقدمها'],
        'stale'        => ['الشحنات الراكدة', 'تنبيهٌ بما لم تتغيّر حالته منذ أيام'],
        'alerts'       => ['قوائم التنبيهات', 'بطاقات المتابعة اليومية — تختار أيّها أدناه'],
        'stuck'        => ['شحنات متعثّرة', 'لم تُسلَّم أو أُجّلت، الأقدم أوّلاً'],
        'governorates' => ['قيد التنفيذ حسب المحافظة', 'أين تتركّز الشحنات المفتوحة'],
        'pickups'      => ['طلبات استلام تنتظر', 'تجّارٌ جهّزوا طرودهم ولم يُسنَد لهم مندوب'],
        'cash_limit'   => ['تجاوزوا سقف النقد', 'مندوبون يُسوّى معهم قبل إسناد شحناتٍ جديدة'],
    ];

    /**
     * ما يُشترط لرؤية كل قسم: المال لمن يرى المال وحده. كانت الأقسام تظهر لكل موظّف،
     * فيرى موظّف الإدخال والكول سنتر نقد المندوبين ومستحقّ التجّار والمبالغ المعلّقة.
     * والاختيار في «خصّص الرئيسية» لا يفتح قسماً لا يملك صاحبه صلاحيته.
     */
    public const SECTION_ABILITIES = [
        'today'        => 'shipments.view',
        'where'        => 'shipments.view',
        'money'        => 'money.view',
        'stale'        => 'shipments.view',
        'alerts'       => null,
        'stuck'        => 'shipments.view',
        'governorates' => 'shipments.view',
        'pickups'      => 'pickups.manage',
        'cash_limit'   => 'money.view',
    ];

    /** بطاقات التنبيه (HomeAlerts) بأسمائها، كلٌّ لمن يفتح ما خلفها */
    public const ALERTS = [
        'tickets'      => 'طلبات المناديب لتغيير المبلغ',
        'operations'   => 'التنبيهات التشغيلية — بعد آخر موعدٍ للتوصيل',
        'duplicates'   => 'إيصالات متكرّرة',
        'with_courier' => 'طلبات عند المندوب منذ ٧٢ ساعة',
        'forced'       => 'واصل إجباري خلال آخر ٢٤ ساعة',
        'unpaid'       => 'مبالغ وصولات لم يتمّ تسديدها',
        'in_transit'   => 'شحنات بين فرعين منذ أكثر من ٢٤ ساعة',
        'returns_away' => 'شحنات راجعة أُرسلت إلى الفرع ولم تُستلم',
        'manifests'    => 'كشوف النقل المرسلة — آخر ٢٤ ساعة',
    ];

    /** ما لا معنى له اختصاراً: الرئيسية نفسها */
    private const NOT_SHORTCUTS = ['dashboard'];

    /**
     * لوحته كما تُرسم: الأقسام الظاهرة، وبطاقات التنبيه، والاختصارات التي يفتحها فعلاً.
     *
     * @return array{sections: array<string, bool>, alerts: list<string>, shortcuts: list<array{id: string, label: string, url: string, icon: string, group: string}>, source: string}
     */
    public static function for(User $user): array
    {
        [$layout, $source] = match (true) {
            is_array($user->home_layout) => [$user->home_layout, 'own'],
            is_array($user->rank?->home_layout) => [$user->rank->home_layout, 'rank'],
            default => [null, 'default'],
        };

        $layout = static::normalise($layout);
        $available = collect(static::shortcuts($user))->keyBy('id');

        return [
            'sections'  => array_fill_keys(array_values(array_filter(
                $layout['sections'], fn (string $section) => static::allowsSection($user, $section),
            )), true),
            'alerts'    => $layout['alerts'],
            'shortcuts' => collect($layout['shortcuts'])
                ->map(fn (string $id) => $available->get($id))->filter()->values()->all(),
            'source'    => $source,
        ];
    }

    /** المحفوظ لصاحبه — الموظّف أو المرتبة — وإلّا الافتراضيّ */
    public static function stored(User|Rank $owner): array
    {
        return static::normalise(is_array($owner->home_layout) ? $owner->home_layout : null);
    }

    /**
     * ما يُحفظ: مفاتيح معروفةٌ وحدها، وبلا تكرار. والفارغ (null) هو الافتراضيّ: الأقسام
     * والتنبيهات كلّها، بلا اختصارات — اللوحة كما كانت قبل التخصيص.
     *
     * @return array{sections: list<string>, alerts: list<string>, shortcuts: list<string>}
     */
    public static function normalise(?array $layout): array
    {
        if ($layout === null) {
            return [
                'sections'  => array_keys(self::SECTIONS),
                'alerts'    => array_keys(self::ALERTS),
                'shortcuts' => [],
            ];
        }

        $known = fn (string $key, array $allowed) => array_values(array_unique(array_filter(
            (array) ($layout[$key] ?? []), fn ($value) => is_string($value) && in_array($value, $allowed, true),
        )));

        return [
            // بترتيب اللوحة لا بترتيب الاختيار: الأقسام مرسومةٌ بأماكنها
            'sections'  => array_values(array_intersect(array_keys(self::SECTIONS), $known('sections', array_keys(self::SECTIONS)))),
            'alerts'    => $known('alerts', array_keys(self::ALERTS)),
            'shortcuts' => array_slice($known('shortcuts', static::shortcutIds()), 0, 24),
        ];
    }

    /**
     * شاشات القائمة اختصاراتٍ، بمجموعاتها: لموظّفٍ ما يفتحه وحده، ولمرتبةٍ ما تفتحه
     * صلاحياتها (والموظّف بعدها يرى منها ما يفتحه هو).
     *
     * @return list<array{id: string, label: string, url: string, icon: string, group: string}>
     */
    public static function shortcuts(User|Rank|null $for = null): array
    {
        $items = [];

        foreach (StaffNavigation::menus() as [$group, $icon, $links]) {
            foreach ($links as $link) {
                [$route, $label, , $ability] = $link;
                $params = $link[4] ?? [];

                if (in_array($route, self::NOT_SHORTCUTS, true)) {
                    continue;
                }

                $allowed = match (true) {
                    $for instanceof User => StaffNavigation::allows($for, $route, $ability),
                    $for instanceof Rank => $ability === null || in_array($ability, $for->abilities ?? [], true),
                    default              => true,
                };

                if (! $allowed) {
                    continue;
                }

                $items[] = [
                    'id'    => StaffNavigation::linkKey($link),
                    'label' => $label,
                    'url'   => route($route, $params),
                    'icon'  => $icon,
                    'group' => $group,
                ];
            }
        }

        return $items;
    }

    /** يرى $user هذا القسم؟ صلاحيته، وما لا صلاحية له يراه الجميع */
    public static function allowsSection(User $user, string $section): bool
    {
        $ability = self::SECTION_ABILITIES[$section] ?? null;

        return $ability === null || $user->can($ability);
    }

    /**
     * الأقسام التي يُختار منها في «خصّص الرئيسية»: لموظّفٍ ما يراه، ولمرتبةٍ ما تفتحه
     * صلاحياتها — فلا يُعرض اختيارٌ لا أثر له.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sectionsFor(User|Rank|null $for = null): array
    {
        return array_filter(self::SECTIONS, function (string $section) use ($for) {
            $ability = self::SECTION_ABILITIES[$section] ?? null;

            return match (true) {
                $ability === null    => true,
                $for instanceof User => $for->can($ability),
                $for instanceof Rank => in_array($ability, $for->abilities ?? [], true),
                default              => true,
            };
        }, ARRAY_FILTER_USE_KEY);
    }

    /** @return list<string> */
    private static function shortcutIds(): array
    {
        return array_column(static::shortcuts(), 'id');
    }

    /** بطاقات التنبيه التي يختارها، بترتيبها في HomeAlerts */
    public static function filterAlerts(array $cards, array $keys): array
    {
        return array_values(array_filter($cards, fn (array $card) => in_array($card['key'], $keys, true)));
    }
}
