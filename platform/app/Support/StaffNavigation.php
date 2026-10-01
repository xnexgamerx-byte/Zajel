<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;

/**
 * الشريط العلوي لموظّفي الشركة.
 *
 * القوائم الاثنتا عشرة بترتيبها في النظام الذي يعمل عليه الموظّفون اليوم
 * (docs/plan/10-live-system-analysis.md §٣): الترتيب ذاكرةُ يدٍ لا ذوق، فمَن
 * ينتقل إلينا يجد «الراجع» حيث ترك «تصفيات الراجع». والأسماء كلماتٌ يومية
 * بسيطة بطلب صاحب النظام (docs/plan/20 §٧). وتحت كل قائمة شاشاتنا
 * التي تقابلها — وكل شاشة كانت في الشريط الجانبي موجودةٌ هنا.
 *
 * كل رابط يحمل صلاحيته: ما لا يُفتح لا يظهر (قائمةٌ تُفضي إلى 403 أسوأ من
 * قائمة قصيرة، وتُطلع الموظّف على ما لا يخصّه)، والقائمة التي لا يبقى فيها
 * رابطٌ لا تظهر.
 */
final class StaffNavigation
{
    /**
     * [العنوان، الأيقونة، الروابط]؛ والرابط [المسار، النصّ، أنماط التمييز، الصلاحية، معاملات الرابط].
     *
     * @return list<array{0: string, 1: string, 2: list<array{0: string, 1: string, 2: list<string>, 3: ?string, 4?: array<string, string>}>}>
     */
    public static function menus(): array
    {
        return [
            ['الرئيسية', 'home', [
                ['dashboard', 'لوحة اليوم', ['dashboard'], null],
                // صفحة الإشعارات نفسها، وقد اختير جمهورها: ثلاثة أفعال كما في النظام المعتاد
                ['announcements.index', 'إشعار لمندوبي الاستلام', ['announcements.*'], 'notify.send', ['audience' => 'pickup_couriers']],
                ['announcements.index', 'إشعار لمندوبي التوصيل', ['announcements.*'], 'notify.send', ['audience' => 'delivery_couriers']],
                ['announcements.index', 'إشعار لكل التجّار', ['announcements.*'], 'notify.send', ['audience' => 'merchants']],
                ['app-ads.index', 'إعلانات التطبيق', ['app-ads.*'], 'notify.send'],
            ]],
            ['الشحنات', 'boxes', [
                ['shipments.index', 'الشحنات', ['shipments.index', 'shipments.show'], 'shipments.view'],
                ['shipments.create', 'شحنة جديدة', ['shipments.create'], 'shipments.create'],
                ['shipments.quick', 'إدخال سريع (حتى ٣٠ شحنة)', ['shipments.quick*'], 'shipments.create'],
                ['shipments.import', 'رفع ملف Excel', ['shipments.import*'], 'shipments.create'],
                // ما رجع إلى تاجره: خرج من القائمة الجارية إلى أرشيفه، لكل تاجرٍ قائمته
                ['shipments.archive', 'الشحنات المؤرشفة', ['shipments.archive'], 'shipments.view'],
            ]],
            ['التوصيل', 'truck', [
                ['shipments.stages', 'كل مراحل النقل', ['shipments.stages'], 'shipments.view'],
                ['shipments.scan', 'استلام بالمسح وإسناد', ['shipments.scan'], 'shipments.status'],
                ['processing.index', 'شحنات للمعالجة', ['processing.*'], 'shipments.status'],
                ['courier-manifests.index', 'كشوف المناديب', ['courier-manifests.*'], 'transport.manage'],
                ['manifests.index', 'كشوف النقل', ['manifests.index', 'manifests.show'], 'transport.manage'],
                ['manifests.inbound', 'الواصل من الفروع', ['manifests.inbound'], 'transport.manage'],
                ['pickup-agents.objections', 'اعتراضات مندوبي الاستلام', ['pickup-agents.objections'], 'money.view'],
            ]],
            ['الاستلام والمخزن', 'clipboard', [
                ['pickups.index', 'طلبات الاستلام', ['pickups.*'], 'pickups.manage'],
                ['bags.index', 'الأكياس', ['bags.*'], 'transport.manage'],
                ['shipments.passed', 'شحنات مرّت على مخزني', ['shipments.passed'], 'shipments.view'],
                ['shipments.trash', 'شحنات ممسوحة', ['shipments.trash'], 'shipments.delete'],
            ]],
            ['الراجع', 'undo', [
                ['returns.incoming', 'استلام الراجع من المندوب', ['returns.incoming'], 'returns.manage'],
                ['returns.sorting', 'فرز الراجع للفروع', ['returns.sorting'], 'returns.manage'],
                ['returns.outgoing', 'تسليم الراجع للتاجر', ['returns.outgoing'], 'returns.manage'],
                ['returns.pickup', 'تسليم الراجع لمندوب الاستلام', ['returns.pickup'], 'returns.manage'],
                ['returns.requests', 'طلبات التجّار لكشف الراجع', ['returns.requests'], 'returns.manage'],
                ['manifests.archive', 'أرشيف الكشوف', ['manifests.archive', 'manifests.print'], 'transport.manage'],
            ]],
            ['الصندوق', 'bank', [
                ['cash.index', 'القاصة', ['cash.index'], 'money.cash'],
                ['money.reconcile', 'مطابقة الدفتر', ['money.reconcile'], 'money.view'],
            ]],
            ['الحسابات والمصاريف', 'cash', [
                ['couriers.cash', 'نقد المندوبين', ['couriers.cash'], 'money.view'],
                ['pickup-agents.index', 'مندوبو الاستلام', ['pickup-agents.index', 'pickup-agents.show'], 'money.view'],
                ['expenses.index', 'المصروفات', ['expenses.index'], 'money.expenses'],
                ['money.accountants', 'حسابات المحاسب', ['money.accountants'], 'money.view'],
                ['branch-accounts.index', 'محاسبة الفروع', ['branch-accounts.index'], 'money.view'],
                ['branch-accounts.debts', 'ديون على الفروع', ['branch-accounts.debts'], 'money.view'],
                ['branch-accounts.remittances', 'استلام مبالغ الفروع', ['branch-accounts.remittances'], 'money.view'],
                ['branch-accounts.deposits', 'التأمينات', ['branch-accounts.deposits'], 'money.view'],
                ['merchant-requests.payments', 'طلبات محاسبة من التجّار', ['merchant-requests.payments'], 'money.view'],
            ]],
            ['التقارير', 'chart', [
                ['reports.index', 'كل التقارير', ['reports.index'], 'reports.view'],
                ['reports.returns', 'أسباب الراجع', ['reports.returns'], 'reports.view'],
                ['reports.couriers', 'أداء المندوبين', ['reports.couriers'], 'reports.view'],
                ['reports.merchants', 'أداء التجّار', ['reports.merchants'], 'reports.view'],
                ['reports.governorates', 'الأداء بالمحافظات', ['reports.governorates'], 'reports.view'],
                ['reports.daily', 'الحركة اليومية', ['reports.daily'], 'reports.view'],
                ['reports.dormant', 'تجّار انقطعوا', ['reports.dormant'], 'reports.view'],
                ['reports.debtors', 'ديون لنا', ['reports.debtors'], 'reports.view'],
                ['reports.changes', 'تتبّع التغييرات', ['reports.changes'], 'reports.view'],
                ['reports.stuck', 'شحنات متأخرة', ['reports.stuck'], 'reports.view'],
                ['reports.entries', 'عدد الشحنات المُدخلة', ['reports.entries'], 'reports.view'],
                ['reports.portal', 'ما رفعه التجّار من بواباتهم', ['reports.portal'], 'reports.view'],
                ['reports.processing', 'المتابعة والمراجعة', ['reports.processing'], 'reports.view'],
                ['reports.special-prices', 'تجّار بأسعار خاصّة', ['reports.special-prices'], 'reports.view'],
                ['reports.unconfirmed', 'دفعات لم يؤكَّد استلامها', ['reports.unconfirmed'], 'reports.view'],
                ['reports.notifications', 'سجلّ الإشعارات', ['reports.notifications'], 'reports.view'],
            ]],
            ['التقارير المالية', 'trend', [
                ['reports.profit', 'أرباح الشحنات', ['reports.profit'], 'reports.financial'],
                ['branch-accounts.statement', 'كشف حساب الفرع', ['branch-accounts.statement*'], 'money.view'],
                ['reports.returns-money', 'مال الرواجع', ['reports.returns-money'], 'reports.financial'],
                ['reports.merchant-profit', 'الأرباح حسب التاجر', ['reports.merchant-profit'], 'reports.financial'],
                ['reports.courier-overcharge', 'حوسب المندوب بتكلفة أعلى', ['reports.courier-overcharge'], 'reports.financial'],
                ['money.position', 'الموقف المالي', ['money.position'], 'money.view'],
                ['money.position.history', 'تاريخ الموقف المالي', ['money.position.history'], 'money.view'],
            ]],
            ['المراجعة', 'review', [
                ['conversations.index', 'المحادثات', ['conversations.*'], 'support.reply'],
                ['control.duplicates', 'شحنات مكرّرة', ['control.duplicates'], 'control.duplicates'],
                ['control.forced', 'واصل إجباري', ['control.forced'], 'control.force'],
                ['control.review', 'تحت المراجعة', ['control.review*'], 'control.review'],
            ]],
            ['الإعدادات', 'building', [
                ['merchants.index', 'التجّار', ['merchants.*'], 'settings.merchants'],
                ['couriers.index', 'المندوبون', ['couriers.index', 'couriers.show', 'couriers.create', 'couriers.edit'], 'settings.couriers'],
                ['users.index', 'المستخدمون', ['users.*'], 'settings.users'],
                ['permissions.index', 'الصلاحيات والمراتب', ['permissions.index', 'permissions.ranks.*'], 'settings.permissions'],
                ['permissions.grants.index', 'صلاحيات استثنائية', ['permissions.grants.*'], 'settings.permissions'],
                ['branches.index', 'الفروع', ['branches.*'], 'settings.branches'],
                ['zones.index', 'المناطق', ['zones.*'], 'settings.zones'],
                ['pricing.index', 'التسعيرات', ['pricing.index', 'pricing.edit'], 'settings.pricing'],
                // لمن يُضيف التجّار: عليه تسري تسعيرة الفرع (صاحب الفرع يحملها)
                ['pricing.branch', 'تسعيرة الفرع', ['pricing.branch'], 'settings.merchants'],
                ['governorate-settings.index', 'إعدادات المحافظات', ['governorate-settings.*'], 'settings.pricing'],
                ['areas.index', 'أجور المناطق والأطراف', ['areas.*'], 'settings.pricing'],
                ['settings.company', 'بيانات الشركة', ['settings.company*'], 'settings.company'],
            ]],
            ['المحاسبة', 'card', [
                ['settlements.couriers.index', 'محاسبة المندوبين', ['settlements.couriers.*'], 'money.view'],
                ['settlements.merchants.index', 'محاسبة التجّار', ['settlements.merchants.*'], 'money.view'],
                ['return-batches.index', 'إيصالات الراجع', ['return-batches.*'], 'returns.manage'],
            ]],
        ];
    }

    /**
     * القوائم كما يراها $user في الطلب الحاليّ: روابطه وحدها، وأيّها الحاليّ،
     * وما ينتظر ردّه.
     *
     * @return list<array{label: string, icon: string, active: bool, badge: int, links: list<array{label: string, url: string, active: bool, badge: int}>}>
     */
    public static function for(User $user, Request $request): array
    {
        // ما ينتظر ردّنا يُعَدّ على الرابط نفسه: لا يُكتشف بفتح الشاشة
        $waiting = $user->can('support.reply')
            ? Conversation::visibleTo($user)->where('status', 'open')->where('last_author', 'merchant')->count()
            : 0;

        $menus = [];

        foreach (static::menus() as [$label, $icon, $links]) {
            $visible = [];

            foreach ($links as $link) {
                [$route, $text, $patterns, $ability] = $link;
                $params = $link[4] ?? [];

                if (! static::allows($user, $route, $ability)) {
                    continue;
                }

                // «تسعيرة الفرع» لمن لا يُدير التسعيرات: من يُديرها يراها كلّها في «التسعيرات»
                if ($route === 'pricing.branch' && $user->can('settings.pricing')) {
                    continue;
                }

                $here = $request->routeIs(...$patterns);

                $visible[] = [
                    'label'  => $text,
                    'url'    => route($route, $params),
                    'here'   => $here,
                    // رابطٌ بمعاملات (جمهور الإشعار) حاليٌّ حين تطابق معاملاته الطلب
                    'active' => $here && collect($params)->every(fn ($value, $key) => $request->query($key) === $value),
                    'badge'  => $route === 'conversations.index' ? $waiting : 0,
                ];
            }

            if ($visible === []) {
                continue;
            }

            $menus[] = [
                'label'  => $label,
                'icon'   => $icon,
                'active' => in_array(true, array_column($visible, 'here'), true),
                'badge'  => array_sum(array_column($visible, 'badge')),
                'links'  => array_map(fn (array $link) => Arr::except($link, 'here'), $visible),
            ];
        }

        return $menus;
    }

    /**
     * يفتح $user هذه الشاشة؟ صلاحيتها، وليست للشركة كلّها (main-branch) وهو في
     * فرعٍ غير الرئيسي. ما لا يُفتح لا يُعرَض رابطاً — في الشريط وفي غيره.
     */
    public static function allows(User $user, string $route, ?string $ability): bool
    {
        if ($ability !== null && ! $user->can($ability)) {
            return false;
        }

        return ! ($user->isBranchLimited()
            && in_array('main-branch', Route::getRoutes()->getByName($route)?->gatherMiddleware() ?? [], true));
    }
}
