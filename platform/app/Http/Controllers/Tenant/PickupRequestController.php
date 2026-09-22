<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\PickupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * الوصلة بين طلب التاجر ومندوب الاستلام.
 *
 * بلا هذه الشاشة يبقى طلب التاجر معلّقاً إلى الأبد — وهو ما كان
 * يحدث فعلاً قبل إضافتها.
 */
class PickupRequestController extends Controller
{
    public function index(Request $request): View
    {
        $pickups = PickupRequest::query()
            ->with(['merchant:id,business_name,phone,address,landmark', 'courier:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when(! $request->query('status'), fn ($q) => $q->whereIn('status', ['pending', 'assigned', 'in_progress']))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        return view('tenant.pickups.index', [
            'pickups'  => $pickups,
            // مندوبو الاستلام فقط: التوصيل وظيفة أخرى
            'couriers' => Courier::picking()->active()->orderBy('name')->get(['id', 'name']),
            'pending'  => PickupRequest::where('status', 'pending')->count(),
        ]);
    }

    public function assign(Request $request, PickupRequest $pickup): RedirectResponse
    {
        $data = $request->validate([
            'courier_id'   => ['required', 'integer'],
            'scheduled_at' => ['nullable', 'date'],
        ], [], ['courier_id' => 'المندوب']);

        $courier = Courier::picking()->active()->find($data['courier_id']);

        if (! $courier) {
            return back()->withErrors(['courier_id' => 'اختر مندوب استلام مفعّلاً.']);
        }

        if ($pickup->status === 'completed') {
            return back()->withErrors(['courier_id' => 'هذا الطلب مُغلق.']);
        }

        $pickup->forceFill([
            'courier_id'   => $courier->id,
            'status'       => 'assigned',
            'assigned_at'  => now(),
            'scheduled_at' => $data['scheduled_at'] ?? $pickup->scheduled_at,
        ])->save();

        return back()->with('success', "أُسند طلب {$pickup->number} إلى {$courier->name}.");
    }

    public function cancel(Request $request, PickupRequest $pickup): RedirectResponse
    {
        $data = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:255'],
        ], [], ['cancel_reason' => 'السبب']);

        if ($pickup->status === 'completed') {
            return back()->withErrors(['cancel_reason' => 'هذا الطلب مُغلق.']);
        }

        $pickup->forceFill([
            'status'        => 'cancelled',
            'cancelled_at'  => now(),
            'cancel_reason' => $data['cancel_reason'],
        ])->save();

        return back()->with('success', "أُلغي طلب {$pickup->number}.");
    }
}
