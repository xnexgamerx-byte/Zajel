<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShipmentRequest;
use App\Models\City;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Governorate;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShipmentController extends Controller
{
    /**
     * قائمة الشحنات — شاشة اليوم لموظّف العمليات وخدمة العملاء.
     *
     * كل شرط هنا مغطّى بفهرس مركّب يبدأ بـ company_id، فالقائمة تبقى
     * سريعة عند 500 شحنة يومياً بعد سنتين من التشغيل لا في أوّل شهر فقط.
     */
    public function index(Request $request): View
    {
        $query = Shipment::query()
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar',
                    'deliveryCourier:id,name'])
            ->visibleTo($request->user())
            ->search($request->query('q'));

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($merchantId = $request->query('merchant_id')) {
            $query->where('merchant_id', $merchantId);
        }

        if ($governorateId = $request->query('governorate_id')) {
            $query->where('governorate_id', $governorateId);
        }

        if ($courierId = $request->query('courier_id')) {
            $query->where('delivery_courier_id', $courierId);
        }

        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $shipments = $query->latest('id')->paginate(config('zajel.per_page'))->withQueryString();

        return view('tenant.shipments.index', [
            'shipments'    => $shipments,
            'statuses'     => ShipmentStatus::cases(),
            'merchants'    => Merchant::orderBy('business_name')->get(['id', 'business_name']),
            'governorates' => Governorate::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar']),
            'couriers'     => Courier::delivering()->active()->orderBy('name')
                ->with('zones.governorate:id,name_ar')->get(['id', 'name']),
            'totals'       => $this->totals($request),
        ]);
    }

    public function create(Request $request): View
    {
        return view('tenant.shipments.create', [
            'merchants'    => Merchant::where('status', 'active')->orderBy('business_name')->get(['id', 'business_name', 'phone']),
            'governorates' => Governorate::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar']),
            'cities'       => City::where('is_active', true)->orderBy('name_ar')->get(['id', 'governorate_id', 'name_ar']),
        ]);
    }

    public function store(StoreShipmentRequest $request, CreateShipment $action): RedirectResponse
    {
        $shipment = $action->handle($request->validated(), $request->user());

        return redirect()
            ->route('shipments.show', $shipment)
            ->with('success', "تم إنشاء الشحنة برقم وصل {$shipment->number}.");
    }

    public function show(Request $request, Shipment $shipment): View
    {
        // ربط المسار بالنموذج يطبّق فلترة الشركة وحدها. بلا هذا السطر
        // يفتح تاجرٌ شحنة تاجر آخر بكتابة رقمها في العنوان.
        abort_unless(
            Shipment::whereKey($shipment->id)->visibleTo($request->user())->exists(),
            404,
        );

        $shipment->load([
            'merchant', 'governorate', 'city', 'deliveryCourier', 'pickupCourier',
            'hub', 'branch', 'lastFailureReason',
            'events.courier:id,name', 'events.failureReason:id,name_ar',
        ]);

        return view('tenant.shipments.show', [
            'shipment'   => $shipment,
            // الخيارات تأتي من خريطة الانتقالات نفسها، فلا تظهر في الواجهة
            // حالة لا يقبلها النظام — الواجهة والمنطق مصدرهما واحد.
            'nextStatuses' => $shipment->status->allowedNext(),
            'couriers'     => Courier::delivering()->active()->orderBy('name')
                ->with('zones.governorate:id,name_ar')->get(['id', 'name']),
            'hubs'         => Hub::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'reasons'      => FailureReason::availableFor($request->user()->company_id)->get(),
        ]);
    }

    /**
     * أرقام أعلى الشاشة — استعلام واحد مجمَّع بدل خمسة عدّادات.
     *
     * toBase() مقصود: يُبقي فلترة الشركة مطبَّقة لكن يُرجع صفوفاً خاماً
     * بلا تحويل status إلى enum، فتصحّ المقارنة مع قائمة الحالات النهائية.
     */
    private function totals(Request $request): array
    {
        $rows = Shipment::query()
            ->visibleTo($request->user())
            ->selectRaw('status, count(*) as c, sum(cod_amount) as cod')
            ->groupBy('status')
            ->toBase()
            ->get();

        $terminal = array_map(fn (ShipmentStatus $s) => $s->value, ShipmentStatus::terminal());
        $open = $rows->whereNotIn('status', $terminal);

        $countOf = fn (ShipmentStatus $status) => (int) $rows
            ->where('status', $status->value)
            ->sum('c');

        return [
            'total'     => (int) $rows->sum('c'),
            'open'      => (int) $open->sum('c'),
            'delivered' => $countOf(ShipmentStatus::Delivered),
            'returned'  => $countOf(ShipmentStatus::Returned),
            'cod_open'  => (int) $open->sum('cod'),
        ];
    }
}
