<?php

namespace App\Http\Controllers\Courier;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\FailureReason;
use App\Models\Manifest;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaskController extends Controller
{
    /**
     * مهام اليوم — مرتّبة بالمحافظة ثم المنطقة.
     *
     * المندوب لا يقرأ جدولاً: يمشي على خطّ. الترتيب الجغرافي يوفّر عليه
     * ذهاباً وإياباً لا يوفّره أي فرز آخر.
     */
    public function index(Request $request): View
    {
        $courier = $request->attributes->get('courier');

        // مندوب النقل بين الفروع (المناورة): مهامّه كشوف النقل التي يحملها، لا شحنات زبائن
        if ($courier->isTransfer()) {
            $mine = fn () => Manifest::where('courier_id', $courier->id)->with(['fromHub:id,name', 'toHub:id,name']);

            return view('courier.transfers', [
                'onTheRoad' => $mine()->where('status', 'dispatched')->orderBy('departed_at')->get(),
                'loading'   => $mine()->where('status', 'draft')->latest('id')->get(),
                'arrived'   => $mine()->where('status', 'arrived')->whereOnDate('arrived_at', today())->latest('arrived_at')->get(),
            ]);
        }

        $tasks = Shipment::query()
            ->where('delivery_courier_id', $courier->id)
            ->where('status', ShipmentStatus::OutForDelivery->value)
            ->with(['governorate:id,name_ar', 'city:id,name_ar', 'merchant:id,business_name'])
            ->orderBy('governorate_id')
            ->orderBy('city_id')
            ->get();

        return view('courier.tasks', [
            'tasks'   => $tasks->groupBy(fn (Shipment $s) => $s->governorate->name_ar),
            // ما يرجع معه إلى المخزن: «راجع مؤكد» بقرار المعالجة، وباقي الواصل الجزئي وقديم الاستبدال
            'returns' => Shipment::query()
                ->where('delivery_courier_id', $courier->id)
                ->where(fn ($q) => \App\Actions\Returns\ReceiveReturns::withCourier($q))
                ->with(['merchant:id,business_name', 'lastFailureReason:id,name_ar'])
                ->orderBy('status_changed_at')
                ->get(),
            // طلب تغيير المبلغ الذي ينتظر جواباً، أو واصلٌ جزئيّ اعتُمد: يُرى على البطاقة
            'tickets' => ShipmentTicket::query()->where('courier_id', $courier->id)
                ->whereIn('shipment_id', $tasks->pluck('id'))
                ->where(fn ($q) => $q->open()->orWhere(fn ($w) => $w->awaitingPartial()))
                ->get()->keyBy('shipment_id'),
            'count'   => $tasks->count(),
            'toCollect' => (int) $tasks->sum('cod_amount'),
            // الأب يرى فريقه: كم بيد كلٍّ وكم نقد — لا عناوين زبائنهم
            'team'    => $courier->subs()
                ->withCount(['deliveries as open_count' => fn ($q) => $q->where('status', ShipmentStatus::OutForDelivery->value)])
                ->orderBy('name')
                ->get(['id', 'name', 'cash_in_hand', 'parent_id']),
        ]);
    }

    public function show(Request $request, Shipment $shipment): View
    {
        $courier = $request->attributes->get('courier');

        // ربط المسار يفلتر بالشركة لا بالمندوب
        abort_unless(
            $shipment->delivery_courier_id === $courier->id || $shipment->pickup_courier_id === $courier->id,
            404,
        );

        $shipment->load(['governorate', 'city', 'merchant:id,business_name,phone', 'lastFailureReason']);

        $canAct = $shipment->status === ShipmentStatus::OutForDelivery && $shipment->delivery_courier_id === $courier->id;

        return view('courier.shipment', [
            'shipment' => $shipment,
            'reasons'  => FailureReason::availableFor($request->user()->company_id)->get(),
            'canAct'   => $canAct,
            // آخر طلبٍ له لتغيير مبلغها في خرجته هذه: ينتظر، أو اعتُمد، أو رُفض
            'ticket'   => $canAct
                ? $shipment->tickets()->where('courier_id', $courier->id)->where('status', '!=', 'closed')
                    ->where('created_at', '>=', $shipment->assigned_at ?? $shipment->status_changed_at)
                    ->first()
                : null,
        ]);
    }

    /** بحث برقم الوصل — يقوم مقام مسح الباركود حتى يصل التطبيق. */
    public function search(Request $request): RedirectResponse
    {
        $courier = $request->attributes->get('courier');
        $term = trim((string) $request->query('q'));

        $shipment = Shipment::query()
            ->where(fn ($q) => $q->where('delivery_courier_id', $courier->id)
                ->orWhere('pickup_courier_id', $courier->id))
            ->search($term)
            ->first();

        if (! $shipment) {
            return back()->withErrors(['q' => "لا توجد شحنة بيدك بالرقم «{$term}»."]);
        }

        return redirect()->route('courier.shipments.show', $shipment);
    }

    /** ما أنجزه اليوم — يراه بنفسه بدل أن يسأل الشركة. */
    public function today(Request $request): View
    {
        $courier = $request->attributes->get('courier');

        $done = Shipment::query()
            ->where('delivery_courier_id', $courier->id)
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereOnDate('status_changed_at', today())
                ->whereIn('status', [
                    ShipmentStatus::Delivered->value,
                    ShipmentStatus::PartiallyDelivered->value,
                    ShipmentStatus::FailedAttempt->value,
                    ShipmentStatus::Postponed->value,
                ]))
                // واصلٌ جزئي سلّمه اليوم ولو مضى باقيه راجعاً: نقده بيده ويُسلَّم معه
                ->orWhere(fn ($w) => $w->whereOnDate('delivered_at', today())))
            ->with(['governorate:id,name_ar', 'lastFailureReason:id,name_ar'])
            ->latest('status_changed_at')
            ->get();

        return view('courier.today', [
            'done'      => $done,
            // كلّها أو بعضها، والاستبدال منها (الوثيقة ٣١)
            'delivered' => $done->filter(fn (Shipment $s) => $s->wasDelivered())->count(),
            'collected' => (int) $done->sum('collected_amount'),
        ]);
    }
}
