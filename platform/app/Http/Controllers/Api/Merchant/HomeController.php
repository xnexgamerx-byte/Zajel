<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AppAd;
use App\Models\Shipment;
use App\Support\FeatureGate;
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

        $hour = (int) now()->format('G');

        return response()->json([
            'greeting' => $hour < 12 ? 'صباح الخير' : 'مساء الخير',
            'name'     => $merchant->owner_name ?: $merchant->business_name,
            'unread'   => Announcement::for($user)->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $user->id))->count(),
            'banners'  => $this->banners(),
            'balance'  => [
                'amount'    => abs((int) $merchant->balance),
                'owed'      => $merchant->balance >= 0,
                'unsettled' => Shipment::where('merchant_id', $merchant->id)
                    ->whereNotNull('delivered_at')->whereNull('merchant_settled_at')->count(),
            ],
            'stats' => [
                'total'       => $counts['all'],
                'delivered'   => $counts['delivered'],
                'in_delivery' => $counts['open'],
                'returns'     => $counts['returns'],
            ],
            'processing' => [
                'count'   => $counts['processing'],
                'allowed' => (bool) $merchant->can_process,
            ],
            'attention' => ['count' => $counts['attention']],
            'tools'     => [
                'import'   => FeatureGate::enabled('excel_import'),
                'waybills' => FeatureGate::enabled('waybills'),
                'support'  => FeatureGate::enabled('conversations'),
                'ai'       => FeatureGate::enabled('order_reading'),
            ],
            'recent' => $this->recent($merchant->id),
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
                'id'    => $ad->id,
                'title' => $ad->title,
                'image' => route('api.app-ads.image', $ad),
                'link'  => $ad->link_url,
            ])->values()->all();
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
            ->with(['city:id,name_ar', 'governorate:id,name_ar'])
            ->orderByRaw('case when status in ('.implode(',', array_fill(0, count($needs), '?')).') then 0 else 1 end', $needs)
            ->orderByDesc('status_changed_at')->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Shipment $s) => ShipmentController::row($s))
            ->all();
    }
}
