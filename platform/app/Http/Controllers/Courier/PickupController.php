<?php

namespace App\Http\Controllers\Courier;

use App\Actions\Shipments\CompletePickup;
use App\Http\Controllers\Controller;
use App\Models\PickupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PickupController extends Controller
{
    public function index(Request $request): View
    {
        $courier = $request->attributes->get('courier');

        return view('courier.pickups', [
            'pickups' => PickupRequest::query()
                ->where('courier_id', $courier->id)
                ->whereIn('status', ['assigned', 'in_progress'])
                ->with('merchant:id,business_name,phone,address,landmark')
                ->orderBy('scheduled_at')
                ->get(),
        ]);
    }

    public function complete(Request $request, PickupRequest $pickup, CompletePickup $action): RedirectResponse
    {
        $courier = $request->attributes->get('courier');

        abort_unless($pickup->courier_id === $courier->id, 404);

        if ($pickup->status === 'completed') {
            return back()->withErrors(['actual_count' => 'هذا الطلب مُغلق بالفعل.']);
        }

        $data = $request->validate([
            'actual_count' => ['required', 'integer', 'min:0', 'max:5000'],
            'note'         => ['nullable', 'string', 'max:500'],
        ], [], ['actual_count' => 'العدد المستلم']);

        $action->handle($pickup, $data['actual_count'], $request->user(), $data['note'] ?? null);

        return redirect()
            ->route('courier.pickups')
            ->with('success', "استُلم {$data['actual_count']} طرداً من {$pickup->merchant->business_name}.");
    }
}
