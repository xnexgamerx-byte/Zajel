<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Support\MerchantPermissions;
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
        // ما في مخزننا وحده: استُلم من التاجر أو بين المراكز، ولم يخرج مع مندوب (docs/plan/87)
        'warehouse' => 'بالمخزن',
        'delivered' => 'مسلمة',
        'processing' => 'للمعالجة',
        'attention' => 'تحتاج انتباهك',
        'returns' => 'راجع مؤكدة',
        // ما انتهى معه — واصلٌ حاسبناه عليه، وراجعٌ سلّمناه إليه — يخرج من التطبيق إلى كشوفه وحدها
        // (docs/plan/86)، ويجده البحث برقمه
    ];

    /**
     * خانات «رحلة شحناتك اليوم» (docs/plan/87): كلّ حالةٍ بخانتها وعددها، وتفتح قائمتها. الجارية وحدها كالشرائح،
     * وبعضها يتداخل: «إعادة توصيل» بالمخزن أو مع المندوب، و«راجع مؤكد» عند المندوب أو في المخزن.
     */
    public const STATES = [
        'all' => 'كل الشحنات',
        'created' => 'جديدة',
        'pickup' => 'بانتظار المندوب',
        'warehouse' => 'بالمخزن',
        'courier' => 'مع المندوب',
        'redelivery' => 'إعادة توصيل',
        'postponed' => 'مؤجلة',
        'processing' => 'للمعالجة',
        'delivered' => 'مسلمة',
        'return_courier' => 'راجع عند المندوب',
        'return_hub' => 'راجع في المخزن',
        'returns' => 'راجع مؤكد',
    ];

    /** «بالمخزن»: الشريحة وخانة «رحلة شحناتك اليوم» بالتعريف نفسه */
    public const WAREHOUSE = [
        ShipmentStatus::PickedUp->value, ShipmentStatus::AtHub->value, ShipmentStatus::InTransit->value,
    ];

    public function index(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS + self::STATES)
            ? (string) $request->query('filter') : 'all';
        // بمحافظةٍ واحدة (docs/plan/86): قيد التوصيل، والمسلّمة، وتحتاج انتباهك، والراجع
        $governorate = (int) $request->query('governorate_id') ?: null;

        $page = Shipment::query()
            ->where('merchant_id', $merchant->id)
            ->when($governorate, fn (Builder $q) => $q->where('governorate_id', $governorate))
            ->with(self::ROW_WITH)
            ->search($request->query('q'))
            // البحث برقمٍ يجد ما انتهى أيضاً، والقائمة بلا بحثٍ للجارية وحدها (docs/plan/81)
            ->where(fn (Builder $q) => $filter === 'all' && filled($request->query('q')) ? $q : self::apply($q, $filter))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'filters' => $this->filters(self::counts($merchant->id)),
            'governorates' => $filter === 'all' ? [] : self::governorates($merchant->id, $filter),
            'data' => collect($page->items())->map(fn (Shipment $s) => self::row($s))->values(),
            'meta' => [
                'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
            ],
        ]);
    }

    /** خانات الحالات بأعدادها (docs/plan/87) */
    public function states(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        $counts = self::stateCounts($merchant->id);

        return response()->json([
            'total' => $counts['all'],
            'data' => collect(self::STATES)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'count' => $counts[$key]])
                ->values(),
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
            // الأجور والمستحقّ لمن يرى المالية (docs/plan/87)، والمبلغ للجميع: هو على الوصل
            'money' => ! MerchantPermissions::allows($request->user(), 'finance') ? [
                'cod' => (int) $shipment->cod_amount,
                'collected' => (int) $shipment->collected_amount,
                'hidden' => true,
            ] : [
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

    /** ما يحمّله صفّ الشحنة معه: منطقتها، ومكان الراجع */
    public const ROW_WITH = [
        'city:id,name_ar', 'governorate:id,name_ar', 'merchant:id,branch_id',
        'hub:id,branch_id,governorate_id', 'hub.governorate:id,name_ar',
    ];

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
            // المؤجّلة بموعدها (docs/plan/86): «مؤجل إلى غداً الساعة 9:00 م»
            'until' => $s->status === ShipmentStatus::Postponed ? $s->scheduled_at?->toIso8601String() : null,
            'status' => $s->status === ShipmentStatus::FailedAttempt ? 'للمعالجة' : $s->statusLabel(),
            // أين الراجع الآن (docs/plan/87): «راجع عند المندوب»…
            'return_place' => self::returnPlace($s),
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

    /**
     * أين الراجع الآن، بكلام التاجر (docs/plan/87). وما سُلِّم إليه خرج من القوائم إلى كشوفه.
     */
    public static function returnPlace(Shipment $s): ?string
    {
        if ($s->status !== ShipmentStatus::Returning) {
            return null;
        }
        if ($s->return_received_at === null) {
            return 'راجع عند المندوب';
        }
        if ($s->current_bag_id !== null) {
            return 'راجع في الطريق إلى فرعك';
        }

        $hub = $s->hub;
        $home = $s->merchant?->branch_id;
        // في مركزٍ غير فرع التاجر — والمجهول «هنا» كما في Shipment::scopeAwayFromHomeBranch
        if ($hub?->branch_id && $home && (int) $hub->branch_id !== (int) $home) {
            $governorate = $hub->governorate?->name_ar;

            return $governorate ? 'راجع عند مركز محافظة '.$governorate : 'راجع في مركزٍ آخر';
        }

        return 'راجع في المخزن';
    }

    /** شرط الشريحة — بالتعريف نفسه الذي تعدّ به الرئيسية */
    public static function apply(Builder $q, string $filter): Builder
    {
        $values = fn (ShipmentStatus ...$s) => array_map(fn ($x) => $x->value, $s);

        return match ($filter) {
            'new' => $q->whereIn('status', ShipmentStatus::enteredValues()),
            'open' => $q->whereIn('status', ShipmentStatus::inDeliveryValues()),
            'warehouse' => $q->whereIn('status', self::WAREHOUSE),
            'created' => $q->where('status', ShipmentStatus::Created->value),
            'pickup' => $q->where('status', ShipmentStatus::PendingPickup->value),
            'courier' => $q->where('status', ShipmentStatus::OutForDelivery->value),
            // كما في Shipment::isRedelivery
            'redelivery' => $q->whereNotNull('redelivery_at')
                ->whereIn('status', $values(ShipmentStatus::OutForDelivery, ShipmentStatus::AtHub)),
            'postponed' => $q->where('status', ShipmentStatus::Postponed->value),
            'return_courier' => $q->where('status', ShipmentStatus::Returning->value)->whereNull('return_received_at'),
            'return_hub' => $q->where('status', ShipmentStatus::Returning->value)->whereNotNull('return_received_at'),
            // ما لم نحاسبه عليه بعد — والمحاسَب في كشوفه وحدها
            'delivered' => $q->where(fn ($w) => $w->where('status', ShipmentStatus::PartiallyDelivered->value)
                ->orWhere(fn ($d) => $d->where('status', ShipmentStatus::Delivered->value)->whereNull('merchant_settled_at'))),
            'processing' => $q->where('status', ShipmentStatus::FailedAttempt->value),
            'attention' => $q->whereIn('status', $values(ShipmentStatus::Postponed, ShipmentStatus::Returning)),
            // ما زال في طريقه إليه — وما سُلِّم إليه في كشوف الراجع وحدها
            'returns' => $q->where('status', ShipmentStatus::Returning->value)->whereNotNull('return_confirmed_at'),
            default => self::current($q),
        };
    }

    /** الجارية: كلّ ما لم ينتهِ معه — لا واصلٌ حاسبناه عليه، ولا راجعٌ سلّمناه إليه */
    public static function current(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereIn('status', array_values(array_diff(
            array_map(fn (ShipmentStatus $s) => $s->value, ShipmentStatus::cases()),
            [ShipmentStatus::Returned->value, ShipmentStatus::Delivered->value],
        )))->orWhere(fn ($d) => $d->where('status', ShipmentStatus::Delivered->value)->whereNull('merchant_settled_at')));
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

        // الواصل الذي حاسبناه عليه والراجع الذي سُلِّم إليه خارج التطبيق، في كشوفه (docs/plan/81، 86)
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
            // «رحلة شحناتك» في الرئيسية (docs/plan/70): أين هي الآن، محطّةً محطّة
            'waiting' => $of(ShipmentStatus::Created, ShipmentStatus::PendingPickup),
            'warehouse' => (int) $rows->only(self::WAREHOUSE)->sum(),
            'courier' => $of(ShipmentStatus::OutForDelivery),
            'returning' => $of(ShipmentStatus::Returning),
        ];
    }

    /**
     * أعداد خانات الحالات (docs/plan/87): الشرائح من counts()، والباقي من عدٍّ واحدٍ بالحالة وعلامتيها.
     *
     * @return array<string, int>
     */
    public static function stateCounts(int $merchantId): array
    {
        $counts = self::counts($merchantId);
        $rows = Shipment::where('merchant_id', $merchantId)
            ->selectRaw('status, case when redelivery_at is null then 0 else 1 end as re,'
                .' case when return_received_at is null then 0 else 1 end as rec, count(*) as c')
            ->groupByRaw('status, case when redelivery_at is null then 0 else 1 end,'
                .' case when return_received_at is null then 0 else 1 end')
            ->toBase()->get();
        $sum = fn (callable $match) => (int) $rows->filter(fn ($r) => $match((string) $r->status, (bool) $r->re, (bool) $r->rec))
            ->sum('c');

        return [
            'all' => $counts['all'],
            'created' => $sum(fn ($s) => $s === ShipmentStatus::Created->value),
            'pickup' => $sum(fn ($s) => $s === ShipmentStatus::PendingPickup->value),
            'warehouse' => $counts['warehouse'],
            'courier' => $counts['courier'],
            'redelivery' => $sum(fn ($s, $re) => $re && in_array($s, [ShipmentStatus::OutForDelivery->value, ShipmentStatus::AtHub->value], true)),
            'postponed' => $sum(fn ($s) => $s === ShipmentStatus::Postponed->value),
            'processing' => $counts['processing'],
            'delivered' => $counts['delivered'],
            'return_courier' => $sum(fn ($s, $re, $rec) => $s === ShipmentStatus::Returning->value && ! $rec),
            'return_hub' => $sum(fn ($s, $re, $rec) => $s === ShipmentStatus::Returning->value && $rec),
            'returns' => $counts['returns'],
        ];
    }

    /**
     * محافظات الشريحة بعدد ما في كلٍّ (docs/plan/86) — للفرز بمحافظة، الأكثر أوّلاً.
     *
     * @return list<array{id: int, name: string, count: int}>
     */
    public static function governorates(int $merchantId, string $filter): array
    {
        $counts = Shipment::query()->where('merchant_id', $merchantId)
            ->where(fn (Builder $q) => self::apply($q, $filter))
            ->whereNotNull('governorate_id')
            ->selectRaw('governorate_id, count(*) as c')->groupBy('governorate_id')
            ->toBase()->get()->pluck('c', 'governorate_id');
        $names = Governorate::query()->whereIn('id', $counts->keys())->pluck('name_ar', 'id');

        return $counts->map(fn ($c, $id) => ['id' => (int) $id, 'name' => (string) ($names[$id] ?? ''), 'count' => (int) $c])
            ->filter(fn ($g) => $g['name'] !== '')
            ->sortByDesc('count')->values()->all();
    }

    /** @param array<string, int> $counts */
    private function filters(array $counts): array
    {
        return collect(self::FILTERS)
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'count' => $counts[$key]])
            ->values()->all();
    }
}
