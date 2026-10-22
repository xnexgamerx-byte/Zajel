<?php

namespace App\Http\Controllers\Courier;

use App\Actions\Couriers\ScanParcel;
use App\Actions\Shipments\CompletePickup;
use App\Http\Controllers\Controller;
use App\Models\PickupRequest;
use App\Support\Arabic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PickupController extends Controller
{
    public function index(Request $request): View
    {
        $courier = $request->attributes->get('courier');

        return view('courier.pickups', [
            'scanRequired' => ScanParcel::required(),
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

        // بمسح الشحنات (docs/plan/72): يُستلم ما مُسح وحده، سطرٌ لكلّ شحنة — والعدد عددها
        if (ScanParcel::required()) {
            $data = $request->validate([
                'codes' => ['required', 'string', 'max:20000'],
                'note'  => ['nullable', 'string', 'max:500'],
            ], ['codes.required' => 'امسح باركود كلّ طردٍ استلمته أو اكتب رقمه.']);

            $ids = [];
            foreach (preg_split('/\R+/u', trim($data['codes'])) ?: [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $found = ScanParcel::resolve($pickup, $line);
                if (is_string($found)) {
                    return back()->withInput()->withErrors(['codes' => $found]);
                }
                $ids[] = $found->id;
            }
            $ids = array_values(array_unique($ids));
            $data['actual_count'] = count($ids);
        } else {
            $data = $request->validate([
                'actual_count' => ['required', 'integer', 'min:0', 'max:5000'],
                'note'         => ['nullable', 'string', 'max:500'],
            ], [], ['actual_count' => 'العدد المستلم']);
            $ids = null;
        }

        $action->handle($pickup, $data['actual_count'], $request->user(), $data['note'] ?? null, $ids);

        return redirect()
            ->route('courier.pickups')
            ->with('success', 'استُلم '.Arabic::parcels((int) $data['actual_count'])." من {$pickup->merchant->business_name}.");
    }
}
