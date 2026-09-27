<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * الشريط العلوي لموظّفي الشركة.
 *
 * القوائم الاثنتا عشرة بأسمائها وترتيبها في النظام الذي يعمل عليه الموظّفون
 * اليوم (docs/plan/10-live-system-analysis.md §٣): الترتيب ذاكرةُ يدٍ لا ذوق،
 * ومَن ينتقل إلينا يجد «تصفيات الراجع» حيث تركها. وتحت كل قائمة شاشاتنا
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
            ['الصفحة الرئيسية', 'home', [
                ['dashboard', 'لوحة اليوم', ['dashboard'], null],
                // صفحة الإشعارات نفسها، وقد اختير جمهورها: ثلاثة أفعال كما في النظام المعتاد
                ['announcements.index', 'إرسال إشعار لكافة مندوبي الاستلام', ['announcements.*'], 'notify.send', ['audience' => 'pickup_couriers']],
                ['announcements.index', 'إرسال إشعار لكافة مندوبي التوصيل', ['announcements.*'], 'notify.send', ['audience' => 'delivery_couriers']],
                ['announcements.index', 'إرسال إشعار لكافة التجّار', ['announcements.*'], 'notify.send', ['audience' => 'merchants']],
            ]],
            ['شحنات العميل', 'boxes', [
                ['shipments.index', 'الشحنات', ['shipments.index', 'shipments.show'], 'shipments.view'],
                ['shipments.create', 'شحنة جديدة', ['shipments.create'], 'shipments.create'],
                ['shipments.quick', 'إدخال سريع (حتى ٣٠ شحنة)', ['shipments.quick*'], 'shipments.create'],
                ['shipments.import', 'رفع من ملف', ['shipments.import*'], 'shipments.create'],
            ]],
            ['عمليات التوصيل', 'truck', [
                ['shipments.stages', 'كل مراحل النقل', ['shipments.stages'], 'shipments.view'],
                ['shipments.scan', 'استلام وصولات في كل المراحل وإسنادها', ['shipments.scan'], 'shipments.status'],
                ['processing.index', 'شحنات للمعالجة', ['processing.*'], 'shipments.status'],
                ['courier-manifests.index', 'كشوف المناديب', ['courier-manifests.*'], 'transport.manage'],
                ['manifests.index', 'كشوف النقل', ['manifests.index', 'manifests.show'], 'transport.manage'],
                ['manifests.inbound', 'وارد المراكز', ['manifests.inbound'], 'transport.manage'],
                ['pickup-agents.objections', 'اعتراضات حصص الاستلام', ['pickup-agents.objections'], 'money.view'],
            ]],
            ['طلبات شحن', 'clipboard', [
                ['pickups.index', 'طلبات الاستلام', ['pickups.*'], 'pickups.manage'],
                ['bags.index', 'الأكياس', ['bags.*'], 'transport.manage'],
                ['shipments.passed', 'شحنات مرّت على مخزني', ['shipments.passed'], 'shipments.view'],
                ['shipments.trash', 'شحنات ممسوحة', ['shipments.trash'], 'shipments.delete'],
            ]],
            ['تصفيات الراجع', 'undo', [
                ['returns.incoming', 'استلام الراجع', ['returns.incoming'], 'returns.manage'],
                ['returns.sorting', 'فرز الراجع للفروع', ['returns.sorting'], 'returns.manage'],
                ['returns.outgoing', 'تسليم الراجع للتاجر', ['returns.outgoing'], 'returns.manage'],
                ['returns.pickup', 'تسليم الراجع لمندوب الاستلام', ['returns.pickup'], 'returns.manage'],
                ['returns.requests', 'طلبات كشف راجع للتجّار', ['returns.requests'], 'returns.manage'],
                ['manifests.archive', 'أرشيف الكشوف', ['manifests.archive', 'manifests.print'], 'transport.manage'],
            ]],
            ['النظام المصرفي', 'bank', [
                ['cash.index', 'القاصة', ['cash.index'], 'money.cash'],
                ['money.reconcile', 'مطابقة الدفتر', ['money.reconcile'], 'money.view'],
            ]],
            ['إيرادات ومصروفات', 'cash', [
                ['couriers.cash', 'نقد المندوبين', ['couriers.cash'], 'money.view'],
                ['pickup-agents.index', 'مندوبو الاستلام', ['pickup-agents.index', 'pickup-agents.show'], 'money.view'],
                ['expenses.index', 'المصروفات', ['expenses.index'], 'money.expenses'],
                ['branch-accounts.index', 'محاسبة الفروع', ['branch-accounts.index'], 'money.view'],
                ['branch-accounts.deposits', 'التأمينات', ['branch-accounts.deposits'], 'money.view'],
                ['merchant-requests.payments', 'طلبات حساب من التجّار', ['merchant-requests.payments'], 'money.view'],
            ]],
            ['تقارير', 'chart', [
                ['reports.index', 'كل التقارير', ['reports.index'], 'reports.view'],
                ['reports.returns', 'لماذا ترجع شحناتي؟', ['reports.returns'], 'reports.view'],
                ['reports.couriers', 'أداء المندوبين', ['reports.couriers'], 'reports.view'],
                ['reports.merchants', 'أداء التجّار', ['reports.merchants'], 'reports.view'],
                ['reports.governorates', 'الأداء بالمحافظات', ['reports.governorates'], 'reports.view'],
                ['reports.daily', 'الحركة اليومية', ['reports.daily'], 'reports.view'],
                ['reports.dormant', 'عملاء منقطعون', ['reports.dormant'], 'reports.view'],
                ['reports.debtors', 'أرصدة مدينة', ['reports.debtors'], 'reports.view'],
                ['reports.changes', 'تتبّع التغييرات', ['reports.changes'], 'reports.view'],
            ]],
            ['تقارير مالية', 'trend', [
                ['reports.profit', 'أرباح الشحنات', ['reports.profit'], 'reports.financial'],
                ['branch-accounts.statement', 'كشف حساب الفرع', ['branch-accounts.statement*'], 'money.view'],
                ['reports.returns-money', 'مال الرواجع', ['reports.returns-money'], 'reports.financial'],
            ]],
            ['المراجعة', 'review', [
                ['conversations.index', 'المحادثات', ['conversations.*'], 'support.reply'],
                ['control.duplicates', 'مشتبه بتكرارها', ['control.duplicates'], 'control.duplicates'],
                ['control.forced', 'واصل إجباري', ['control.forced'], 'control.force'],
                ['control.review', 'تحت المراجعة', ['control.review*'], 'control.review'],
            ]],
            ['إعدادات الفروع', 'building', [
                ['merchants.index', 'التجّار', ['merchants.*'], 'settings.merchants'],
                ['couriers.index', 'المندوبون', ['couriers.index', 'couriers.show', 'couriers.create', 'couriers.edit'], 'settings.couriers'],
                ['users.index', 'المستخدمون', ['users.*'], 'settings.users'],
                ['permissions.index', 'الصلاحيات والمراتب', ['permissions.index', 'permissions.ranks.*'], 'settings.permissions'],
                ['permissions.grants.index', 'صلاحيات استثنائية', ['permissions.grants.*'], 'settings.permissions'],
                ['branches.index', 'الفروع', ['branches.*'], 'settings.branches'],
                ['zones.index', 'المناطق', ['zones.*'], 'settings.zones'],
                ['pricing.index', 'التسعيرات', ['pricing.index', 'pricing.edit'], 'settings.pricing'],
                ['governorate-settings.index', 'إعدادات المحافظات', ['governorate-settings.*'], 'settings.pricing'],
                ['areas.index', 'أجور المناطق والأطراف', ['areas.*'], 'settings.pricing'],
                ['settings.company', 'بيانات الشركة', ['settings.company*'], 'settings.company'],
            ]],
            ['الدفعات', 'card', [
                ['settlements.couriers.index', 'تسوية المندوبين', ['settlements.couriers.*'], 'money.view'],
                ['settlements.merchants.index', 'تسوية التجّار', ['settlements.merchants.*'], 'money.view'],
                ['return-batches.index', 'دفعات الراجع', ['return-batches.*'], 'returns.manage'],
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

                if ($ability !== null && ! $user->can($ability)) {
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
}
