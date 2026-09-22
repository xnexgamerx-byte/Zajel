<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Shipments\CreateShipment;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PortalShipmentRequest;
use App\Models\City;
use App\Models\Governorate;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShipmentController extends Controller
{
    public function index(Request $request): View
    {
        $merchant = $request->attributes->get('merchant');

        $shipments = Shipment::query()
            ->with(['governorate:id,name_ar', 'city:id,name_ar'])
            ->where('merchant_id', $merchant->id)
            ->search($request->query('q'))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('portal.shipments.index', [
            'shipments' => $shipments,
            'statuses'  => ShipmentStatus::cases(),
        ]);
    }

    public function create(): View
    {
        return view('portal.shipments.create', [
            'governorates' => Governorate::where('is_active', true)->orderBy('sort_order')->get(['id', 'name_ar']),
            'cities'       => City::where('is_active', true)->orderBy('name_ar')->get(['id', 'governorate_id', 'name_ar']),
        ]);
    }

    public function store(PortalShipmentRequest $request, CreateShipment $action): RedirectResponse
    {
        $shipment = $action->handle(
            $request->validated() + ['source' => 'merchant_portal'],
            $request->user(),
        );

        return redirect()
            ->route('portal.shipments.show', $shipment)
            ->with('success', "أُنشئت الشحنة برقم وصل {$shipment->number}. اطلب استلاماً متى جهّزت طرودك.");
    }

    public function show(Request $request, Shipment $shipment): View
    {
        $merchant = $request->attributes->get('merchant');

        // ربط المسار بالنموذج يفلتر بالشركة لا بالتاجر
        abort_unless($shipment->merchant_id === $merchant->id, 404);

        $shipment->load([
            'governorate', 'city', 'lastFailureReason',
            'events' => fn ($q) => $q->where('event_type', 'status_change')->orderBy('id'),
        ]);

        return view('portal.shipments.show', compact('shipment'));
    }
}
