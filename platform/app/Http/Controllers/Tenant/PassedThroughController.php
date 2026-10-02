<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Hub;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «شحنات مرّت على مخزني» كما في المعتاد: كل شحنةٍ دخلت مراكز الفرع ولو خرجت
 * منها — راجعٌ فُرز إلى فرعٍ آخر، أو شحنةٌ عبرت في كيس. سجلّ الشحنة يحمل مكان
 * كل حدث، ومنه يُعرف المرور.
 *
 * الموظّف المقيَّد بفرع يرى ما مرّ بفرعه وحده، وقد لا يراها اليوم في قائمته
 * لأنها صارت في فرعٍ آخر: فتُعرض بلا هاتف زبونها، ولا تُفتح إلّا ما يراه.
 */
class PassedThroughController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $branches = Branch::orderBy('name')->get(['id', 'name', 'is_main']);

        $branchId = $user->isBranchLimited()
            ? $user->branch_id
            : ($request->integer('branch_id') ?: $branches->firstWhere('is_main', true)?->id ?? $branches->first()?->id);

        $hubs = Hub::where('branch_id', $branchId)->pluck('id');

        $query = Shipment::query()
            ->whereIn('shipments.id', ShipmentEvent::query()->select('shipment_id')->whereIn('hub_id', $hubs))
            ->when($request->query('status'), fn ($q, $status) => $q->where('shipments.status', $status))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('shipments.number', $term)
                ->orWhere('shipments.barcode', $term)->orWhere('shipments.recipient_phone', $term)))
            ->when($request->integer('created_in'), fn ($q, $id) => $q->where('shipments.branch_id', $id))
            ->when($request->date('from'), fn ($q, $d) => $q->where('shipments.created_at', '>=', $d->startOfDay()))
            ->when($request->date('to'), fn ($q, $d) => $q->where('shipments.created_at', '<', $d->startOfDay()->addDay()));

        $shipments = $query
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'branch:id,name', 'hub:id,name,branch_id', 'hub.branch:id,name'])
            ->latest('shipments.id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        // ما يستطيع فتحه منها: الباقي يُعرض ولا يُفتح
        $visible = Shipment::query()->visibleTo($user)->whereIn('id', $shipments->pluck('id'))->pluck('id')->flip();

        return view('tenant.shipments.passed', [
            'shipments' => $shipments,
            'visible'   => $visible,
            'branches'  => $branches,
            'branchId'  => $branchId,
            'locked'    => $user->isBranchLimited(),
            'statuses'  => ShipmentStatus::cases(),
        ]);
    }
}
