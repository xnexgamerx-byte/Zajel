<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Support\ShipmentLocation;
use App\Support\Tracking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «شحناتي» في تطبيق التاجر (docs/plan/48 §٤-١): القائمة بفلاترها وبحثها، والشحنة
 * بمسارها وحسابها — ما تعرضه بوابة التاجر في الموقع، لشحناته هو وحدها.
 */
class ShipmentController extends Controller
{
    /** شرائح القائمة بترتيبها في التطبيق — وكلٌّ يطابق عدّاده في الرئيسية */
    public const FILTERS = [
        'all' => 'الكل',
        // الجديدة في «الطابعة» لا في «قيد التوصيل» (docs/plan/79)
        'new' => 'جديدة',
        'open' => 'قيد التوصيل',
        'delivered' => 'مسلمة',
        'processing' => 'للمعالجة',
        'attention' => 'تحتاج انتباهك',
        'returns' => 'راجع مؤكدة',
        // ما انتهى معه (docs/plan/81): واصلٌ حاسبناه عليه، وراجعٌ سلّمناه إليه
        'archive' => 'المؤرشفة',
    ];

    public function index(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS)
            ? (string) $request->query('filter') : 'all';

        $page = Shipment::query()
            ->where('merchant_id', $merchant->id)
            ->with(['city:id,name_ar', 'governorate:id,name_ar'])
            ->search($request->query('q'))
            // البحث برقمٍ يجد المؤرشفة أيضاً، والقائمة بلا بحثٍ للجارية وحدها (docs/plan/81)
            ->where(fn (Builder $q) => $filter === 'all' && filled($request->query('q')) ? $q : self::apply($q, $filter))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'filters' => $this->filters(self::counts($merchant->id)),
            'data' => collect($page->items())->map(fn (Shipment $s) => self::row($s))->values(),
            'meta' => [
                'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');

        // ربط المسار يفلتر بالشركة لا بالتاجر
        abort_unless((int) $shipment->merchant_id === (int) $merchant->id, 404);

        $shipment->load([
            'governorate:id,name_ar', 'city:id,name_ar', 'lastFailureReason:id,name_ar,category',
            'deliveryCourier:id,name,is_intermediary,hidden_from_merchants', 'pickupCourier:id,name,is_intermediary,hidden_from_merchants',
            'events' => fn ($q) => $q->whereIn('event_type', ShipmentEvent::MERCHANT_EVENTS)->orderBy('id'),
        ]);

        $open = $shipment->status->isOpen();
        // مندوبٌ أخفته الشركة عن العميل (docs/plan/81): لا اسمه ولا موقعه
        $carrier = $shipment->deliveryCourier ?? $shipment->pickupCourier;
        $hidden = (bool) $carrier?->hiddenFromMerchants();
        $returnFee = $shipment->status === ShipmentStatus::Returned ? (int) $shipment->return_fee : 0;

        return response()->json([
            ...self::row($shipment),
            'created_at' => $shipment->created_at?->toIso8601String(),
            'reference' => $shipment->merchant_reference,
            // للتاجر وحده يرسله لزبونه: لا يُسلَّم الطرد إلّا به
            'delivery_code' => $open ? $shipment->delivery_code : null,
            'failure' => $open && $shipment->lastFailureReason ? [
                'reason' => $shipment->lastFailureReason->name_ar,
                'attempts' => (int) $shipment->attempts_count,
            ] : null,
            'tracking_url' => Tracking::url($shipment),
            // تتبّعه الآن (docs/plan/59): من معه الطرد، وآخر ما جرى عليه ومتى
            'tracking' => [
                'courier' => $open && ! $hidden ? $carrier?->merchantFacingName() : null,
                'status' => $shipment->status === ShipmentStatus::FailedAttempt ? 'للمعالجة' : $shipment->statusLabel(),
                'at' => ($shipment->status_changed_at ?? $shipment->created_at)?->toIso8601String(),
                // على الخريطة ما دامت لم تُحسم (docs/plan/61): دقيقٌ من هاتف المندوب، أو تقريبيٌّ بمحافظتها
                'location' => $open && ! $hidden ? ShipmentLocation::of($shipment) : null,
            ],
            'timeline' => $shipment->events->map(fn (ShipmentEvent $e) => [
                'title' => $e->merchantHeadline(),
                'note' => $e->merchantNote(),
                'at' => $e->created_at?->toIso8601String(),
            ])->values(),
            'recipient' => [
                'name' => $shipment->recipient_name,
                'phone' => $shipment->recipient_phone,
                'phone_alt' => $shipment->recipient_phone_alt,
                'governorate' => $shipment->governorate?->name_ar,
                'city' => $shipment->city?->name_ar,
                'address' => $shipment->address,
                'landmark' => $shipment->landmark,
                'pieces' => (int) $shipment->pieces_count,
                'type' => Shipment::TYPES[$shipment->type] ?? $shipment->type,
                'size' => Shipment::SIZES[$shipment->size] ?? $shipment->size,
                'goods' => $shipment->description,
            ],
            'money' => [
                'cod' => (int) $shipment->cod_amount,
                'collected' => (int) $shipment->collected_amount,
                'delivery_fee' => (int) $shipment->delivery_fee,
                'cod_fee' => (int) $shipment->cod_fee,
                'return_fee' => $returnFee,
                'due' => abs((int) $shipment->merchant_due),
                'owed' => (int) $shipment->merchant_due >= 0,
            ],
        ]);
    }

    /** صفّ الشحنة في القوائم — والرئيسية تستعمله لآخر الشحنات */
    public static function row(Shipment $s): array
    {
        return [
            'id' => $s->id,
            'number' => $s->number,
            'name' => $s->recipient_name ?: $s->recipient_phone,
            'area' => $s->city?->name_ar ?? $s->governorate?->name_ar,
            // المحافظة وحدها، فيُعرض «المحافظة · المنطقة» كاملاً (docs/plan/70)
            'governorate' => $s->governorate?->name_ar,
            'city' => $s->city?->name_ar,
            'amount' => (int) $s->cod_amount,
            'at' => ($s->status_changed_at ?? $s->created_at)?->toIso8601String(),
            'status' => $s->status === ShipmentStatus::FailedAttempt ? 'للمعالجة' : $s->statusLabel(),
            // «للمعالجة» بلون الشركة في التطبيق، والراجع أحمر (docs/plan/50)، والمؤجل أصفر (docs/plan/79)
            'tone' => match ($s->status) {
                ShipmentStatus::FailedAttempt => 'urgent',
                ShipmentStatus::Postponed => 'yellow',
                default => $s->statusColor(),
            },
            'urgent' => in_array($s->status, [
                ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed, ShipmentStatus::Returning,
            ], true),
        ];
    }

    /** شرط الشريحة — بالتعريف نفسه الذي تعدّ به الرئيسية */
    public static function apply(Builder $q, string $filter): Builder
    {
        $values = fn (ShipmentStatus ...$s) => array_map(fn ($x) => $x->value, $s);

        return match ($filter) {
            'new' => $q->whereIn('status', ShipmentStatus::enteredValues()),
            'open' => $q->whereIn('status', ShipmentStatus::inDeliveryValues()),
            // ما لم نحاسبه عليه بعد — والمحاسَب في «المؤرشفة»
            'delivered' => $q->where(fn ($w) => $w->where('status', ShipmentStatus::PartiallyDelivered->value)
                ->orWhere(fn ($d) => $d->where('status', ShipmentStatus::Delivered->value)->whereNull('merchant_settled_at'))),
            'processing' => $q->where('status', ShipmentStatus::FailedAttempt->value),
            'attention' => $q->whereIn('status', $values(ShipmentStatus::Postponed, ShipmentStatus::Returning)),
            // ما زال في طريقه إليه — وما سُلِّم إليه في «المؤرشفة»
            'returns' => $q->where('status', ShipmentStatus::Returning->value)->whereNotNull('return_confirmed_at'),
            'archive' => self::archived($q),
            default => self::current($q),
        };
    }

    /** الجارية: كلّ ما ليس في «المؤرشفة» */
    public static function current(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereIn('status', array_values(array_diff(
            array_map(fn (ShipmentStatus $s) => $s->value, ShipmentStatus::cases()),
            [ShipmentStatus::Returned->value, ShipmentStatus::Delivered->value],
        )))->orWhere(fn ($d) => $d->where('status', ShipmentStatus::Delivered->value)->whereNull('merchant_settled_at')));
    }

    /**
     * «المؤرشفة» (docs/plan/81): ما انتهى مع التاجر فخرج من قوائمه وأرقامه — راجعٌ سلّمناه إليه، وواصلٌ
     * حاسبناه عليه (merchant_settled_at). يبقى في أرشيفه، ويجده بالبحث برقمه، وفي كشوف حسابه.
     */
    public static function archived(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->where('status', ShipmentStatus::Returned->value)
            ->orWhere(fn ($d) => $d->where('status', ShipmentStatus::Delivered->value)->whereNotNull('merchant_settled_at')));
    }

    /**
     * عدد كل شريحة في استعلامين: عدّ الحالات مجمَّعاً، والراجع المؤكَّد وحده.
     *
     * @return array<string, int>
     */
    public static function counts(int $merchantId): array
    {
        $rows = Shipment::where('merchant_id', $merchantId)
            ->selectRaw('status, count(*) as c')->groupBy('status')
            ->toBase()->get()->pluck('c', 'status')->map(fn ($c) => (int) $c);

        $of = fn (ShipmentStatus ...$s) => (int) collect($s)->sum(fn ($x) => $rows[$x->value] ?? 0);

        // الواصل الذي حاسبناه عليه في «المؤرشفة» مع الراجع الذي سُلِّم إليه (docs/plan/81)
        $settled = Shipment::where('merchant_id', $merchantId)->where('status', ShipmentStatus::Delivered->value)
            ->whereNotNull('merchant_settled_at')->count();
        $archived = $of(ShipmentStatus::Returned) + $settled;

        return [
            'all' => (int) $rows->sum() - $archived,
            'new' => (int) $rows->only(ShipmentStatus::enteredValues())->sum(),
            'open' => (int) $rows->only(ShipmentStatus::inDeliveryValues())->sum(),
            'delivered' => $of(ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered) - $settled,
            'processing' => $of(ShipmentStatus::FailedAttempt),
            'attention' => $of(ShipmentStatus::Postponed, ShipmentStatus::Returning),
            'returns' => Shipment::where('merchant_id', $merchantId)
                ->where('status', ShipmentStatus::Returning->value)->whereNotNull('return_confirmed_at')->count(),
            'archive' => $archived,
            // «رحلة شحناتك» في الرئيسية (docs/plan/70): أين هي الآن، محطّةً محطّة
            'waiting' => $of(ShipmentStatus::Created, ShipmentStatus::PendingPickup),
            'warehouse' => $of(ShipmentStatus::PickedUp, ShipmentStatus::AtHub, ShipmentStatus::InTransit),
            'courier' => $of(ShipmentStatus::OutForDelivery),
            'returning' => $of(ShipmentStatus::Returning),
        ];
    }

    /** @param array<string, int> $counts */
    private function filters(array $counts): array
    {
        return collect(self::FILTERS)
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'count' => $counts[$key]])
            ->values()->all();
    }
}
