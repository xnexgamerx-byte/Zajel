<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Shipment;
use App\Services\Couriers\CourierManifests;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * كشوف مناديب التوصيل — ما بيد كل مندوب الآن، ورقةً تُوقَّع.
 *
 * النظام المرجعي يسمّيها allagentsmanifests، وهي أكثر ما يُطبَع في يوم
 * شركة التوصيل: المندوب يأخذ الشحنات ويوقّع على عددها ومبالغها، وعند
 * عودته يُطابَق ما سلّمه وما أرجعه وما بقي بيده بهذه الورقة وحدها.
 *
 * والكشف هنا **لقطة حيّة موقَّتة** لا مستندٌ مجمَّد: ما بيد المندوب
 * الآن، وعليه ساعة الطباعة. والتجميد يقع حيث يجب أن يقع — في كشف
 * التسوية (courier_settlements) الذي يُثبِّت الشحنات والمبالغ عند
 * المحاسبة. ورقةُ عهدةٍ تُجمَّد عند كل طباعة تُنتج عشرات المستندات
 * المتناقضة عن اليوم الواحد.
 */
class CourierManifestController extends Controller
{
    /**
     * كل الكشوف — لا يوم واحد (docs/plan/38): كشفٌ لكل مندوبٍ عن كل يومٍ خرج فيه، بما صارت
     * إليه شحناته، مع البحث بالمندوب والمدّة. و«بيدهم الآن» تبويبٌ في الشاشة نفسها.
     */
    public function index(Request $request, CourierManifests $manifests): View
    {
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query($key)) ? (string) $request->query($key) : null;
        $filters = [
            'q'          => mb_substr(trim((string) $request->query('q')), 0, 60),
            'courier_id' => $request->integer('courier_id') ?: null,
            'from'       => $date('from'),
            'to'         => $date('to'),
        ];

        if ($request->query('tab') === 'now') {
            return $this->now($request, $filters);
        }

        return view('tenant.courier_manifests.history', [
            'filters'   => $filters,
            'manifests' => $manifests->page($request->user(), $filters),
            'couriers'  => Courier::withTrashed()->visibleTo($request->user())->whereIn('type', ['delivery', 'pickup'])
                ->orderBy('name')->get(['id', 'name']),
            'buckets'   => CourierManifests::BUCKETS,
        ]);
    }

    /** كشف مندوبٍ في يومٍ بعينه: شحناته بما صارت إليه، ورقةً تُطبع */
    public function day(Request $request, Courier $courier, string $date, CourierManifests $manifests): View
    {
        abort_unless(Courier::withTrashed()->visibleTo($request->user())->whereKey($courier->id)->exists(), 404);
        $day = Carbon::parse($date);

        $shipments = $manifests->shipmentsOf($request->user(), $courier, $day);

        return view('tenant.courier_manifests.day', [
            'courier'   => $courier,
            'day'       => $day,
            'shipments' => $shipments,
            'buckets'   => $shipments->countBy(fn (Shipment $s) => CourierManifests::bucketOf($s, $courier->id)),
            'labels'    => CourierManifests::BUCKETS,
            'totals'    => (object) [
                'shipments' => $shipments->count(),
                'cod'       => (int) $shipments->sum('cod_amount'),
                'collected' => (int) $shipments->whereNotNull('delivered_at')->sum('collected_amount'),
            ],
        ]);
    }

    /** مَن بيده شيء الآن، وكم، وبكم. */
    protected function now(Request $request, array $filters): View
    {
        // مناديب فرعه وحدهم إن كان مقيَّداً بفرع — ولو محذوفين وبيدهم عهدة
        $held = Shipment::query()
            ->status(ShipmentStatus::OutForDelivery)
            ->whereNotNull('delivery_courier_id')
            ->when($request->user()->isBranchLimited(), fn ($q) => $q->whereIn(
                'delivery_courier_id', Courier::withTrashed()->visibleTo($request->user())->select('id')))
            ->selectRaw('delivery_courier_id,
                         count(*) as shipments,
                         sum(cod_amount) as cod,
                         min(assigned_at) as oldest')
            ->groupBy('delivery_courier_id')
            ->toBase()
            ->get()
            ->keyBy('delivery_courier_id');

        /*
        | مَن بيده شحنة يظهر — مهما كان نوعه أو حالته.
        |
        | كانت القائمة مفلترة بـ delivering()، فأسقطت مندوب استلامٍ
        | أُسنِدت إليه ٤٠٩١ شحنة: عهدةٌ ومالٌ لا يظهران في أي شاشة.
        | الفلترة بالنوع تصلح لاختيار مَن يُسنَد إليه، لا لعرض مَن
        | بيده. والموقوف والمحذوف أولى بالظهور لا أحقّ بالإخفاء.
        */
        $couriers = Courier::withTrashed()
            ->visibleTo($request->user())
            ->whereIn('id', $held->keys()->all() ?: [0])
            // البحث بالاسم أو الكود أو الهاتف
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$filters['q'].'%')
                ->orWhere('code', 'like', $filters['q'].'%')
                ->orWhere('phone', 'like', '%'.\App\Support\Phone::latinDigits($filters['q']).'%')))
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'type', 'status', 'cash_in_hand', 'cash_limit', 'branch_id', 'deleted_at']);

        $held = $held->only($couriers->pluck('id')->all());

        return view('tenant.courier_manifests.index', [
            'filters'  => $filters,
            'couriers' => $couriers,
            'held'     => $held,
            'totals'   => (object) [
                'shipments' => (int) $held->sum('shipments'),
                'cod'       => (int) $held->sum('cod'),
                'couriers'  => $couriers->count(),
            ],
        ]);
    }

    /** ورقة مندوب واحد، جاهزة للطباعة والتوقيع. */
    public function show(Request $request, Courier $courier): View
    {
        $shipments = Shipment::query()
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar'])
            ->where('delivery_courier_id', $courier->id)
            ->status(ShipmentStatus::OutForDelivery)
            ->orderBy('governorate_id')
            ->orderBy('city_id')
            ->orderBy('id')
            ->get();

        return view('tenant.courier_manifests.show', [
            'courier'   => $courier,
            'shipments' => $shipments,
            'totals'    => (object) [
                'shipments' => $shipments->count(),
                'cod'       => (int) $shipments->sum('cod_amount'),
                'pieces'    => (int) $shipments->sum('pieces_count'),
            ],
        ]);
    }
}
