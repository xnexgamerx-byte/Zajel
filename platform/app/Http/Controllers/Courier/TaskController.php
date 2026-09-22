<?php

namespace App\Http\Controllers\Courier;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\FailureReason;
use App\Models\Shipment;
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

        $tasks = Shipment::query()
            ->where('delivery_courier_id', $courier->id)
            ->where('status', ShipmentStatus::OutForDelivery->value)
            ->with(['governorate:id,name_ar', 'city:id,name_ar', 'merchant:id,business_name'])
            ->orderBy('governorate_id')
            ->orderBy('city_id')
            ->get();

        return view('courier.tasks', [
            'tasks'   => $tasks->groupBy(fn (Shipment $s) => $s->governorate->name_ar),
            'count'   => $tasks->count(),
            'toCollect' => (int) $tasks->sum('cod_amount'),
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

        return view('courier.shipment', [
            'shipment' => $shipment,
            'reasons'  => FailureReason::availableFor($request->user()->company_id)->get(),
            'canAct'   => $shipment->status === ShipmentStatus::OutForDelivery,
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
            ->whereOnDate('status_changed_at', today())
            ->whereIn('status', [
                ShipmentStatus::Delivered->value,
                ShipmentStatus::PartiallyDelivered->value,
                ShipmentStatus::FailedAttempt->value,
                ShipmentStatus::Postponed->value,
            ])
            ->with(['governorate:id,name_ar', 'lastFailureReason:id,name_ar'])
            ->latest('status_changed_at')
            ->get();

        return view('courier.today', [
            'done'      => $done,
            'delivered' => $done->filter(fn (Shipment $s) => $s->status === ShipmentStatus::Delivered)->count(),
            'collected' => (int) $done->sum('collected_amount'),
        ]);
    }
}
