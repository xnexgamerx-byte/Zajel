<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Shipment;
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
    /** مَن بيده شيء، وكم، وبكم. */
    public function index(Request $request): View
    {
        $held = Shipment::query()
            ->status(ShipmentStatus::OutForDelivery)
            ->whereNotNull('delivery_courier_id')
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
            ->whereIn('id', $held->keys()->all() ?: [0])
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'type', 'status', 'cash_in_hand', 'cash_limit', 'branch_id', 'deleted_at']);

        return view('tenant.courier_manifests.index', [
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
