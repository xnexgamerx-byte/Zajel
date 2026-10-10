<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
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
        'all'        => 'الكل',
        'open'       => 'قيد التوصيل',
        'delivered'  => 'مسلمة',
        'processing' => 'للمعالجة',
        'attention'  => 'تحتاج انتباهك',
        'returns'    => 'راجع مؤكدة',
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
            ->where(fn (Builder $q) => self::apply($q, $filter))
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
            'events' => fn ($q) => $q->whereIn('event_type', ShipmentEvent::MERCHANT_EVENTS)->orderBy('id'),
        ]);

        $open = $shipment->status->isOpen();
        $returnFee = $shipment->status === ShipmentStatus::Returned ? (int) $shipment->return_fee : 0;

        return response()->json([
            ...self::row($shipment),
            'created_at'    => $shipment->created_at?->toIso8601String(),
            'reference'     => $shipment->merchant_reference,
            // للتاجر وحده يرسله لزبونه: لا يُسلَّم الطرد إلّا به
            'delivery_code' => $open ? $shipment->delivery_code : null,
            'failure'       => $open && $shipment->lastFailureReason ? [
                'reason'   => $shipment->lastFailureReason->name_ar,
                'attempts' => (int) $shipment->attempts_count,
            ] : null,
            'tracking_url' => Tracking::url($shipment),
            'timeline'     => $shipment->events->map(fn (ShipmentEvent $e) => [
                'title' => $e->merchantHeadline(),
                'note'  => $e->merchantNote(),
                'at'    => $e->created_at?->toIso8601String(),
            ])->values(),
            'recipient' => [
                'name'        => $shipment->recipient_name,
                'phone'       => $shipment->recipient_phone,
                'phone_alt'   => $shipment->recipient_phone_alt,
                'governorate' => $shipment->governorate?->name_ar,
                'city'        => $shipment->city?->name_ar,
                'address'     => $shipment->address,
                'landmark'    => $shipment->landmark,
                'pieces'      => (int) $shipment->pieces_count,
                'type'        => Shipment::TYPES[$shipment->type] ?? $shipment->type,
                'size'        => Shipment::SIZES[$shipment->size] ?? $shipment->size,
                'goods'       => $shipment->description,
            ],
            'money' => [
                'cod'          => (int) $shipment->cod_amount,
                'collected'    => (int) $shipment->collected_amount,
                'delivery_fee' => (int) $shipment->delivery_fee,
                'cod_fee'      => (int) $shipment->cod_fee,
                'return_fee'   => $returnFee,
                'due'          => abs((int) $shipment->merchant_due),
                'owed'         => (int) $shipment->merchant_due >= 0,
            ],
        ]);
    }

    /** صفّ الشحنة في القوائم — والرئيسية تستعمله لآخر الشحنات */
    public static function row(Shipment $s): array
    {
        return [
            'id'     => $s->id,
            'number' => $s->number,
            'name'   => $s->recipient_name ?: $s->recipient_phone,
            'area'   => $s->city?->name_ar ?? $s->governorate?->name_ar,
            'amount' => (int) $s->cod_amount,
            'at'     => ($s->status_changed_at ?? $s->created_at)?->toIso8601String(),
            'status' => $s->status === ShipmentStatus::FailedAttempt ? 'للمعالجة' : $s->statusLabel(),
            // «للمعالجة» بلون الشركة في التطبيق، والراجع أحمر (docs/plan/50)
            'tone'   => $s->status === ShipmentStatus::FailedAttempt ? 'urgent' : $s->statusColor(),
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
            'open'       => $q->whereIn('status', ShipmentStatus::openValues()),
            'delivered'  => $q->whereIn('status', $values(ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered)),
            'processing' => $q->where('status', ShipmentStatus::FailedAttempt->value),
            'attention'  => $q->whereIn('status', $values(ShipmentStatus::Postponed, ShipmentStatus::Returning)),
            'returns'    => $q->where(fn ($w) => $w->where('status', ShipmentStatus::Returned->value)
                ->orWhere(fn ($r) => $r->where('status', ShipmentStatus::Returning->value)->whereNotNull('return_confirmed_at'))),
            default      => $q,
        };
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

        return [
            'all'        => (int) $rows->sum(),
            'open'       => (int) $rows->only(ShipmentStatus::openValues())->sum(),
            'delivered'  => $of(ShipmentStatus::Delivered, ShipmentStatus::PartiallyDelivered),
            'processing' => $of(ShipmentStatus::FailedAttempt),
            'attention'  => $of(ShipmentStatus::Postponed, ShipmentStatus::Returning),
            'returns'    => $of(ShipmentStatus::Returned) + Shipment::where('merchant_id', $merchantId)
                ->where('status', ShipmentStatus::Returning->value)->whereNotNull('return_confirmed_at')->count(),
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
