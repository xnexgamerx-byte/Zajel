<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Actions\Support\Converse;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AppAd;
use App\Models\Conversation;
use App\Models\Shipment;
use App\Support\FeatureGate;
use App\Support\MerchantBalance;
use App\Support\MerchantPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * رئيسية تطبيق التاجر (docs/plan/48) في طلبٍ واحد: التحيّة والرصيد والعدّادات الأربعة
 * و«للمعالجة» و«تحتاج انتباهك» وآخر الشحنات — بأرقام بوابة التاجر نفسها، فما يراه في
 * التطبيق هو ما يراه في الموقع.
 */
class HomeController extends Controller
{
    /** «تحتاج انتباهك»: تعثّرت بعد المحاولة — تأجيلٌ أو رجوعٌ لم يصل مخزننا */
    private const ATTENTION = [ShipmentStatus::Postponed, ShipmentStatus::Returning];

    public function __invoke(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $user = $request->user();

        // عدّادات الرئيسية هي شرائح «شحناتي» نفسها: الرقم على البطاقة عددُ ما يُفتح منها
        $counts = ShipmentController::counts($merchant->id);

        $hour = (int) now('Asia/Baghdad')->format('G');
        // موظّف التاجر (docs/plan/87): ما لم يُفتح له لا يصله — لا رصيدٌ لموظّف الإدخال، ولا شحناتٌ لمن لا يراها
        $can = fn (string $p) => MerchantPermissions::allows($user, $p);
        $shipments = $can('shipments');

        return response()->json([
            'greeting' => $hour < 12 ? 'صباح الخير' : 'مساء الخير',
            'name' => MerchantPermissions::isOwner($user) ? ($merchant->owner_name ?: $merchant->business_name) : $user->name,
            'permissions' => MerchantPermissions::of($user),
            // صورته أو شعاره، وإلّا فارغةٌ يضغطها ليضعها (docs/plan/59)
            'logo' => ProfileController::logoUrl($merchant),
            // الجرس: إعلاناتٌ لم يقرأها، وردودٌ من الشركة على محادثاته
            'unread' => Announcement::for($user)->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))->count()
                + (! $can('support') ? 0 : Conversation::where('merchant_id', $merchant->id)->where('merchant_unread', true)
                    ->where('last_author', Converse::STAFF)->count()),
            'banners' => $this->banners(),
            // إجمالي المستحقات والمتاح للسحب، وما قيد المطابقة بسببه (docs/plan/49)
            'balance' => $can('finance') ? MerchantBalance::of($merchant)->toArray() : null,
            'stats' => ! $shipments ? null : [
                'total' => $counts['all'],
                'delivered' => $counts['delivered'],
                'in_delivery' => $counts['open'],
                'returns' => $counts['returns'],
                // «رحلة شحناتك» (docs/plan/70): بانتظار مندوب ← بالمخزن ← مع المندوب ← سُلّمت اليوم، وما يرجع إليه
                'waiting' => $counts['waiting'],
                'warehouse' => $counts['warehouse'],
                'courier' => $counts['courier'],
                'delivered_today' => Shipment::where('merchant_id', $merchant->id)
                    ->whereIn('status', [ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value])
                    ->where('delivered_at', '>=', now('Asia/Baghdad')->startOfDay()->timezone(config('app.timezone')))->count(),
                'returning' => $counts['returning'],
            ],
            // «الطابعة» (docs/plan/79): الجديدة للطباعة، وما طُبع ينتظر المندوب
            'printer' => $can('print') ? LabelController::counts($merchant->id) : null,
            'processing' => [
                'count' => $can('processing') ? $counts['processing'] : 0,
                'allowed' => (bool) $merchant->can_process && $can('processing'),
            ],
            // وتفاصيلها في قائمة الرئيسية قبل «آخر الشحنات» (docs/plan/61): هي الأهمّ
            'attention' => $shipments
                ? ['count' => $counts['attention'], 'items' => $this->attention($merchant->id)]
                : ['count' => 0, 'items' => []],
            'tools' => [
                'import' => FeatureGate::enabled('excel_import') && $can('create'),
                'waybills' => FeatureGate::enabled('waybills') && $can('create'),
                'support' => FeatureGate::enabled('conversations') && $can('support'),
                'ai' => FeatureGate::enabled('order_reading') && $can('create'),
                // موظّفو المتجر: لصاحبه، ما دامت الميزة مفتوحة
                'staff' => FeatureGate::enabled('merchant_staff') && $can(MerchantPermissions::OWNER),
            ],
            'recent' => $shipments ? $this->recent($merchant->id) : [],
        ]);
    }

    /** إعلانات التطبيق للتجّار (P7) — وما ضاعت صورته يُتخطّى كما في الموقع */
    private function banners(): array
    {
        if (! FeatureGate::enabled('app_ads')) {
            return [];
        }

        return AppAd::shownTo('merchants')->limit(6)->get(['id', 'title', 'link_url', 'image_path'])
            ->filter(fn (AppAd $ad) => Storage::disk('local')->exists($ad->image_path))
            ->map(fn (AppAd $ad) => [
                'id' => $ad->id,
                'title' => $ad->title,
                'image' => route('api.app-ads.image', $ad),
                'link' => $ad->link_url,
            ])->values()->all();
    }

    /**
     * «تحتاج انتباهك» بتفاصيلها (docs/plan/61): مؤجَّلةٌ إلى موعدها، أو راجعةٌ لم تصل مخزننا —
     * ولماذا، وكم محاولة، وإلى متى أُجّلت (لا ملاحظات الموظّفين: قد تكون خاصّة). الأقدم ركوداً أوّلاً: هو الأحوج إلى مكالمة.
     *
     * @return list<array<string, mixed>>
     */
    private function attention(int $merchantId): array
    {
        return Shipment::where('merchant_id', $merchantId)
            ->whereIn('status', array_map(fn ($s) => $s->value, self::ATTENTION))
            ->with([...ShipmentController::ROW_WITH, 'lastFailureReason:id,name_ar'])
            ->orderBy('status_changed_at')->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn (Shipment $s) => ShipmentController::row($s) + [
                'reason' => $s->status === ShipmentStatus::Returning ? $s->returnReason() : $s->lastFailureReason?->name_ar,
                'attempts' => (int) $s->attempts_count,
                'until' => $s->status === ShipmentStatus::Postponed ? $s->scheduled_at?->toIso8601String() : null,
            ])
            ->all();
    }

    /**
     * آخر الشحنات: ما يحتاج قراره أوّلاً، ثم الأحدث حركةً.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recent(int $merchantId): array
    {
        $needs = array_map(fn ($s) => $s->value, [ShipmentStatus::FailedAttempt, ...self::ATTENTION]);

        return Shipment::where('merchant_id', $merchantId)
            // المؤرشفة لا تعود في الرئيسية (docs/plan/81)
            ->where(fn ($q) => ShipmentController::current($q))
            ->with(ShipmentController::ROW_WITH)
            ->orderByRaw('case when status in ('.implode(',', array_fill(0, count($needs), '?')).') then 0 else 1 end', $needs)
            ->orderByDesc('status_changed_at')->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Shipment $s) => ShipmentController::row($s))
            ->all();
    }
}
