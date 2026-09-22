<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ReadsMerchantStats;
use App\Models\PickupRequest;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    use ReadsMerchantStats;

    public function __invoke(Request $request): View
    {
        $merchant = $request->attributes->get('merchant');

        return view('portal.dashboard', [
            'counts'  => $this->statusCounts($merchant),
            'recent'  => Shipment::where('merchant_id', $merchant->id)
                ->with('governorate:id,name_ar')
                ->latest('id')->limit(8)->get(),
            'pickups' => PickupRequest::where('merchant_id', $merchant->id)
                ->whereIn('status', ['pending', 'assigned', 'in_progress'])
                ->latest('id')->get(),
            'attention' => Shipment::where('merchant_id', $merchant->id)
                ->whereIn('status', [
                    ShipmentStatus::FailedAttempt->value,
                    ShipmentStatus::Postponed->value,
                    ShipmentStatus::Returning->value,
                ])
                ->with('lastFailureReason:id,name_ar,category')
                ->latest('status_changed_at')->limit(10)->get(),
        ]);
    }
}
