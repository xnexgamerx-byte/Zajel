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
 * القوائم مرتّبةٌ بما تفعله كل شاشة لا بترتيب نظامٍ آخر (docs/plan/21 §١):
 * الشحنات، ثم رحلتها في التوصيل، ثم الراجع، ثم الحسابات المالية يوماً بيوم، ثم
 * الموقف المالي والفروع، ثم التقارير والمتابعة والإعدادات. وأسماؤها كلماتٌ يومية
 * بسيطة يفهمها كل موظّف لا المحاسب وحده (docs/plan/20 §٧).
 * والقائمة ذات الرابط الواحد رابطٌ مباشر لا قائمة تنسدل («الرئيسية»).
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
            // لوحة اليوم وحدها: رابطٌ مباشر لا قائمة تنسدل
            ['الرئيسية', 'home', [
                ['dashboard', 'لوحة اليوم', ['dashboard'], null],
            ]],
            // كل ما يخصّ الشحنة نفسها: إدخالها، وقائمتها، وما انتهى منها
            ['الشحنات', 'boxes', [
                ['shipments.index', 'كل الشحنات', ['shipments.index', 'shipments.show', 'shipments.edit'], 'shipments.view'],
                ['shipments.create', 'شحنة جديدة', ['shipments.create'], 'shipments.create'],
                ['shipments.quick', 'إدخال سريع (حتى ٣٠ شحنة)', ['shipments.quick*'], 'shipments.create'],
                ['shipments.import', 'رفع ملف Excel', ['shipments.import*'], 'shipments.create'],
                // الوصل المطبوع مسبقاً: يكتب عليه التاجر بيده، ويُدخَل بمسحه
                ['shipments.waybill', 'شحنة من وصلٍ مطبوع', ['shipments.waybill'], 'shipments.create'],
                ['waybill-books.index', 'دفاتر الوصولات المطبوعة', ['waybill-books.*'], 'shipments.create'],
                // ما رجع إلى تاجره: خرج من القائمة الجارية إلى أرشيفه، لكل تاجرٍ قائمته
                ['shipments.archive', 'الشحنات المؤرشفة', ['shipments.archive'], 'shipments.view'],
                ['shipments.passed', 'شحنات مرّت على مخزني', ['shipments.passed'], 'shipments.view'],
                ['shipments.trash', 'شحنات ممسوحة', ['shipments.trash'], 'shipments.delete'],
            ]],
            // رحلة الشحنة يوماً بيوم: من استلامها من التاجر حتى الزبون، وبين الفروع
            ['التوصيل', 'truck', [
                ['shipments.stages', 'كل مراحل النقل', ['shipments.stages'], 'shipments.view'],
                ['pickups.index', 'طلبات الاستلام', ['pickups.*'], 'pickups.manage'],
                ['shipments.scan', 'استلام وتوزيع بالمسح', ['shipments.scan'], 'shipments.status'],
                ['courier-manifests.index', 'كشوف المناديب', ['courier-manifests.*'], 'transport.manage'],
                ['processing.index', 'شحنات لم تُسلَّم (للمعالجة)', ['processing.*'], 'shipments.status'],
                ['bags.index', 'الأكياس', ['bags.*'], 'transport.manage'],
                ['manifests.index', 'كشوف النقل بين الفروع', ['manifests.index', 'manifests.show'], 'transport.manage'],
                ['manifests.inbound', 'الواصل من الفروع', ['manifests.inbound'], 'transport.manage'],
                ['manifests.archive', 'أرشيف الكشوف', ['manifests.archive', 'manifests.print'], 'transport.manage'],
            ]],
            // الراجع من المندوب إلى المخزن، ومن المخزن إلى تاجره
            ['الراجع', 'undo', [
                ['returns.incoming', 'استلام الراجع من المندوب', ['returns.incoming'], 'returns.manage'],
                ['returns.sorting', 'فرز الراجع للفروع', ['returns.sorting'], 'returns.manage'],
                ['returns.outgoing', 'تسليم الراجع للتاجر', ['returns.outgoing'], 'returns.manage'],
                ['returns.pickup', 'تسليم الراجع لمندوب الاستلام', ['returns.pickup'], 'returns.manage'],
                ['returns.requests', 'طلبات التجّار لكشف الراجع', ['returns.requests'], 'returns.manage'],
                ['return-batches.index', 'إيصالات الراجع', ['return-batches.*'], 'returns.manage'],
            ]],
            // مال اليوم: ما يدخل الصندوق وما يُدفع، والمحاسبة مع المناديب والتجّار
            ['الحسابات المالية', 'cash', [
                ['cash.index', 'الصندوق', ['cash.index'], 'money.cash'],
                ['prepaid-fees.index', 'استلام أجور مدفوعة مقدّماً', ['prepaid-fees.*'], 'money.cash'],
                ['couriers.cash', 'النقد بيد المندوبين', ['couriers.cash'], 'money.view'],
                ['settlements.couriers.index', 'محاسبة المندوبين', ['settlements.couriers.*'], 'money.view'],
                ['settlements.merchants.index', 'محاسبة التجّار', ['settlements.merchants.*'], 'money.view'],
                ['merchant-requests.payments', 'طلبات محاسبة من التجّار', ['merchant-requests.payments'], 'money.view'],
                ['expenses.index', 'المصروفات', ['expenses.index'], 'money.expenses'],
                ['pickup-agents.index', 'حسابات مندوبي الاستلام', ['pickup-agents.index', 'pickup-agents.show'], 'money.view'],
                ['pickup-agents.objections', 'اعتراضات مندوبي الاستلام', ['pickup-agents.objections'], 'money.view'],
            ]],
            // الصورة الكاملة: موقف الشركة، وتدقيق حساباتها، وحساب كل فرعٍ مع غيره
            ['الموقف المالي والفروع', 'bank', [
                ['money.position', 'الموقف المالي', ['money.position'], 'money.view'],
                ['money.position.history', 'تاريخ الموقف المالي', ['money.position.history'], 'money.view'],
                ['money.reconcile', 'تدقيق الحسابات', ['money.reconcile'], 'money.view'],
                ['money.accountants', 'قبض ودفع الموظّفين', ['money.accountants'], 'money.view'],
                ['branch-accounts.index', 'محاسبة الفروع', ['branch-accounts.index'], 'money.view'],
                ['branch-accounts.statement', 'كشف حساب الفرع', ['branch-accounts.statement*'], 'money.view'],
                ['branch-accounts.debts', 'ديون على الفروع', ['branch-accounts.debts'], 'money.view'],
                ['branch-accounts.remittances', 'استلام مبالغ الفروع', ['branch-accounts.remittances'], 'money.view'],
                ['branch-accounts.deposits', 'تأمينات التجّار', ['branch-accounts.deposits'], 'money.view'],
            ]],
            // الأكثر سؤالاً هنا، والباقي كلّه في «كل التقارير»
            ['التقارير', 'chart', [
                ['reports.index', 'كل التقارير', ['reports.index'], 'reports.view'],
                ['reports.daily', 'الحركة اليومية', ['reports.daily'], 'reports.view'],
                ['reports.returns', 'أسباب الراجع', ['reports.returns'], 'reports.view'],
                ['reports.couriers', 'أداء المندوبين', ['reports.couriers'], 'reports.view'],
                ['reports.merchants', 'أداء التجّار', ['reports.merchants'], 'reports.view'],
                ['reports.governorates', 'الأداء بالمحافظات', ['reports.governorates'], 'reports.view'],
                ['reports.stuck', 'شحنات متأخرة', ['reports.stuck'], 'reports.view'],
                ['reports.profit', 'أرباح الشحنات', ['reports.profit'], 'reports.financial'],
            ]],
            // الكلام مع التجّار والمناديب، وما يُراجَع قبل أن يمضي
            ['المتابعة', 'review', [
                ['conversations.index', 'المحادثات', ['conversations.*'], 'support.reply'],
                ['announcements.index', 'إشعار لكل التجّار', ['announcements.*'], 'notify.send', ['audience' => 'merchants']],
                ['announcements.index', 'إشعار لمندوبي التوصيل', ['announcements.*'], 'notify.send', ['audience' => 'delivery_couriers']],
                ['announcements.index', 'إشعار لمندوبي الاستلام', ['announcements.*'], 'notify.send', ['audience' => 'pickup_couriers']],
                ['app-ads.index', 'إعلانات التطبيق', ['app-ads.*'], 'notify.send'],
                ['control.review', 'تحت المراجعة', ['control.review*'], 'control.review'],
                ['control.duplicates', 'شحنات مكرّرة', ['control.duplicates'], 'control.duplicates'],
                ['control.forced', 'واصل إجباري', ['control.forced'], 'control.force'],
            ]],
            // الناس والأسعار والشركة: ما يُضبط مرّةً ويُعدَّل أحياناً
            ['الإعدادات', 'building', [
                ['merchants.index', 'التجّار', ['merchants.*'], 'settings.merchants'],
                ['couriers.index', 'المندوبون', ['couriers.index', 'couriers.show', 'couriers.create', 'couriers.edit'], 'settings.couriers'],
                ['zones.index', 'مناطق المندوبين', ['zones.*'], 'settings.zones'],
                ['users.index', 'المستخدمون', ['users.*'], 'settings.users'],
                ['permissions.index', 'الصلاحيات والمراتب', ['permissions.index', 'permissions.ranks.*'], 'settings.permissions'],
                ['permissions.grants.index', 'صلاحيات استثنائية', ['permissions.grants.*'], 'settings.permissions'],
                ['branches.index', 'الفروع', ['branches.*'], 'settings.branches'],
                ['pricing.index', 'التسعيرات', ['pricing.index', 'pricing.edit'], 'settings.pricing'],
                // لمن يُضيف التجّار: عليه تسري تسعيرة الفرع (صاحب الفرع يحملها)
                ['pricing.branch', 'تسعيرة الفرع', ['pricing.branch'], 'settings.merchants'],
                ['governorate-settings.index', 'إعدادات المحافظات', ['governorate-settings.*'], 'settings.pricing'],
                ['areas.index', 'أجور المناطق والأطراف', ['areas.*'], 'settings.pricing'],
                ['settings.company', 'بيانات الشركة', ['settings.company*'], 'settings.company'],
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
                // رابطٌ واحد يبقى: يُفتح بضغطةٍ لا بقائمةٍ فيها سطرٌ واحد
                'url'    => count($visible) === 1 ? $visible[0]['url'] : null,
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
