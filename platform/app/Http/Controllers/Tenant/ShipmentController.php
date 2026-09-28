<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\CreateShipment;
use App\Actions\Shipments\UpdateShipment;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShipmentRequest;
use App\Http\Requests\UpdateShipmentRequest;
use App\Models\Branch;
use App\Models\City;
use App\Models\Conversation;
use App\Models\Courier;
use App\Models\FailureReason;
use App\Models\Governorate;
use App\Models\Hub;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Services\Shipments\ShipmentFilters;
use App\Services\Shipments\ShipmentStages;
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
            ->with(['merchant:id,business_name,is_vip', 'governorate:id,name_ar', 'city:id,name_ar',
                    'deliveryCourier:id,name'])
            ->visibleTo($request->user());

        ShipmentFilters::apply($query, $request);

        $shipments = $query->latest('id')->paginate(config('zajel.per_page'))->withQueryString();

        return view('tenant.shipments.index', [
            'shipments'    => $shipments,
            'statuses'     => ShipmentStatus::cases(),
            'stage'        => ShipmentStages::find($request->query('stage')),
            'merchants'    => Merchant::visibleTo($request->user())->orderBy('business_name')->get(['id', 'business_name']),
            'governorates' => Governorate::where('is_active', true)->orderedForCompany()->get(['id', 'name_ar']),
            'cities'       => City::where('is_active', true)->orderBy('name_ar')->get(['id', 'governorate_id', 'name_ar']),
            'couriers'     => Courier::delivering()->active()->visibleTo($request->user())->orderBy('name')
                ->with('zones.governorate:id,name_ar')->get(['id', 'name']),
            'pickupCouriers' => Courier::picking()->visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
            'branches'     => Branch::orderBy('name')->get(['id', 'name']),
            'reasons'      => FailureReason::availableFor($request->user()->company_id)->get(['id', 'name_ar']),
            'advanced'     => ShipmentFilters::hasAdvanced($request),
            'filters'      => ShipmentFilters::active($request),
            'totals'       => $this->totals($request),
        ]);
    }

    /**
     * كل مراحل النقل: عدّادٌ لكل مرحلة، وأقدم ما فيها — والعدّاد يفتح قائمته.
     *
     * استعلامٌ لكل مرحلة لا استعلامٌ واحد: شروط المراحل ليست كلّها حالة
     * (الراجع على الرفّ، والفرع البعيد، والواصل غير المحاسَب عليه)، وكلٌّ منها
     * يقرأ فهرسه. ست عشرة عدّاداً في أجزاءٍ من الثانية.
     */
    public function stages(Request $request): View
    {
        $groups = collect(ShipmentStages::groups())->map(function (array $group) use ($request) {
            $group['stages'] = collect($group['stages'])->map(function (array $stage) use ($request) {
                $row = ($stage['apply'])(Shipment::query()->visibleTo($request->user()))
                    ->toBase()
                    ->selectRaw('count(*) as total, min(shipments.status_changed_at) as oldest')
                    ->first();

                return $stage + [
                    'count'  => (int) ($row->total ?? 0),
                    'oldest' => $row?->oldest ? \Illuminate\Support\Carbon::parse($row->oldest) : null,
                ];
            })->all();

            return $group;
        })->all();

        return view('tenant.shipments.stages', ['groups' => $groups]);
    }

    public function create(Request $request): View
    {
        return view('tenant.shipments.create', [
            'merchants'    => Merchant::where('status', 'active')->visibleTo($request->user())->orderBy('business_name')->get(['id', 'business_name', 'phone']),
            'governorates' => Governorate::offered()->get(['id', 'name_ar']),
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

    public function edit(Request $request, Shipment $shipment): View
    {
        $this->ensureVisible($request, $shipment);

        abort_unless(
            UpdateShipment::editable($shipment),
            403,
            'لا تُعدَّل شحنةٌ بعد تسليمها أو إرجاعها أو إلغائها — مالها قُيِّد في الحسابات.',
        );

        return view('tenant.shipments.edit', [
            'shipment'     => $shipment->load('merchant:id,business_name'),
            'reroutable'   => UpdateShipment::reroutable($shipment),
            'governorates' => Governorate::offered()->orWhere('id', $shipment->governorate_id)->get(['id', 'name_ar']),
            'cities'       => City::where('is_active', true)->orderBy('name_ar')->get(['id', 'governorate_id', 'name_ar']),
        ]);
    }

    public function update(UpdateShipmentRequest $request, Shipment $shipment, UpdateShipment $action): RedirectResponse
    {
        $this->ensureVisible($request, $shipment);

        $action->handle($shipment, $request->validated(), $request->user());

        return redirect()
            ->route('shipments.show', $shipment)
            ->with('success', "حُفظت بيانات الشحنة {$shipment->number}.");
    }

    public function show(Request $request, Shipment $shipment): View
    {
        $this->ensureVisible($request, $shipment);

        $shipment->load([
            'merchant', 'governorate', 'city', 'deliveryCourier.parent:id,name', 'pickupCourier',
            'hub', 'branch', 'lastFailureReason',
            'events.courier:id,name', 'events.failureReason:id,name_ar',
        ]);

        return view('tenant.shipments.show', [
            'shipment'   => $shipment,
            // الخيارات تأتي من خريطة الانتقالات نفسها، فلا تظهر في الواجهة
            // حالة لا يقبلها النظام — الواجهة والمنطق مصدرهما واحد.
            'nextStatuses' => $shipment->status->allowedNext(),
            'couriers'     => Courier::delivering()->active()->visibleTo($request->user())->orderBy('name')
                ->with('zones.governorate:id,name_ar')->get(['id', 'name']),
            'hubs'         => Hub::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'reasons'      => FailureReason::availableFor($request->user()->company_id)->get(),
            // «محادثة الشحنة»: ما دار مع التاجر عنها، لمن يردّ على المحادثات
            'conversations' => $request->user()->can('support.reply')
                ? Conversation::visibleTo($request->user())->where('shipment_id', $shipment->id)
                    ->orderByDesc('last_message_at')->get(['id', 'subject', 'status', 'last_author', 'last_message_at', 'staff_unread'])
                : collect(),
        ]);
    }

    /**
     * ربط المسار بالنموذج يطبّق فلترة الشركة وحدها. بلا هذا الفحص يفتح
     * موظّفٌ مقيَّد بفرعٍ شحنةَ فرعٍ آخر — أو يعدّلها — بكتابة رقمها في العنوان.
     */
    private function ensureVisible(Request $request, Shipment $shipment): void
    {
        abort_unless(
            Shipment::whereKey($shipment->id)->visibleTo($request->user())->exists(),
            404,
        );
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

        $open = $rows->whereIn('status', ShipmentStatus::openValues());

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
