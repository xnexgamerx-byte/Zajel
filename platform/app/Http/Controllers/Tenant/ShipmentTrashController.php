<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\DeleteShipment;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «شحنات ممسوحة» كما في المعتاد: ما مُسح، بمن مسحه ومتى ولماذا، وزرّ «استرجاع».
 * وفلاترها: الوصل، والتاجر، وتاريخ المسح، و«مُسحت من خلال».
 */
class ShipmentTrashController extends Controller
{
    public function index(Request $request): View
    {
        $shipments = Shipment::onlyTrashed()
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar'])
            ->visibleTo($request->user())
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('number', $term)->orWhere('barcode', $term)->orWhere('recipient_phone', $term)))
            ->when($request->integer('merchant_id'), fn ($q, $id) => $q->where('merchant_id', $id))
            ->when($request->integer('deleted_by'), fn ($q, $id) => $q->where('deleted_by_user_id', $id))
            ->when($request->date('from'), fn ($q, $from) => $q->where('deleted_at', '>=', $from->startOfDay()))
            ->when($request->date('to'), fn ($q, $to) => $q->where('deleted_at', '<', $to->startOfDay()->addDay()))
            ->latest('deleted_at')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.shipments.trash', [
            'shipments' => $shipments,
            'merchants' => Merchant::visibleTo($request->user())->orderBy('business_name')->get(['id', 'business_name']),
            // من مسح شيئاً مما يراه: قائمةٌ قصيرة لا كل الموظّفين
            'deleters'  => User::whereIn('id', Shipment::onlyTrashed()->visibleTo($request->user())
                    ->whereNotNull('deleted_by_user_id')->select('deleted_by_user_id')->distinct())
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function destroy(Request $request, Shipment $shipment, DeleteShipment $action): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ], ['reason.required' => 'اكتب سبب المسح: يُقرأ يوم يُسأل عنها.'], ['reason' => 'السبب']);

        $action->delete($shipment, $data['reason'], $request->user());

        return redirect()->route('shipments.trash')->with('success', "مُسحت الشحنة {$shipment->number}. تُسترجع من هنا إن لزم.");
    }

    public function restore(Request $request, int $id, DeleteShipment $action): RedirectResponse
    {
        $shipment = Shipment::onlyTrashed()->visibleTo($request->user())->findOrFail($id);

        $action->restore($shipment, $request->user());

        return redirect()->route('shipments.show', $shipment)->with('success', "استُرجعت الشحنة {$shipment->number} كما كانت.");
    }
}
