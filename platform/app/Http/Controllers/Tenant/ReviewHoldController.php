<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * «تحت المراجعة»: شحنات تاجرٍ معلَّق للتدقيق لا تخرج مع مندوب حتى يجيزها
 * موظّف — واحدةً واحدة أو دفعة، ويُكتب في سجلّها من أجازها ومتى.
 */
class ReviewHoldController extends Controller
{
    public function index(Request $request): View
    {
        return view('tenant.control.review', [
            'shipments' => Shipment::query()
                ->visibleTo($request->user())
                ->heldForReview()
                ->whereIn('shipments.status', \App\Enums\ShipmentStatus::openValues())
                ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar'])
                ->orderBy('shipments.review_hold_at')
                ->paginate(config('zajel.per_page'))
                ->withQueryString(),
        ]);
    }

    public function approve(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shipment_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'shipment_ids.*' => ['integer'],
        ], [], ['shipment_ids' => 'الشحنات']);

        $approved = DB::transaction(function () use ($data, $request) {
            $shipments = Shipment::query()
                ->visibleTo($request->user())
                ->heldForReview()
                ->whereIn('shipments.id', $data['shipment_ids'])
                ->lockForUpdate()
                ->get();

            foreach ($shipments as $shipment) {
                $shipment->forceFill(['reviewed_at' => now(), 'reviewed_by_user_id' => $request->user()->id])->save();

                ShipmentEvent::create([
                    'shipment_id' => $shipment->id,
                    'from_status' => $shipment->status->value,
                    'to_status'   => $shipment->status->value,
                    'event_type'  => 'reviewed',
                    'actor_type'  => 'user',
                    'actor_id'    => $request->user()->id,
                    'actor_name'  => $request->user()->name,
                    'note'        => 'أُجيزت بعد المراجعة',
                    'ip'          => $request->ip(),
                ]);
            }

            return $shipments->count();
        });

        return back()->with('success', $approved
            ? 'أُجيزت '.\App\Support\Arabic::shipments($approved).': تخرج مع المندوب الآن.'
            : 'لم تُجز أيّ شحنة — قد تكون أُجيزت سلفاً.');
    }
}
