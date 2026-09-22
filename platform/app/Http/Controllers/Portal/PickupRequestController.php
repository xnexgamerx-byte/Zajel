<?php

namespace App\Http\Controllers\Portal;

use App\Models\PickupRequest;
use App\Http\Controllers\Controller;
use App\Services\SequenceGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PickupRequestController extends Controller
{
    public function index(Request $request): View
    {
        $merchant = $request->attributes->get('merchant');

        return view('portal.pickups.index', [
            'pickups' => PickupRequest::where('merchant_id', $merchant->id)
                ->with('courier:id,name,phone')
                ->latest('id')
                ->paginate(config('zajel.per_page')),
        ]);
    }

    public function store(Request $request, SequenceGenerator $sequences): RedirectResponse
    {
        $merchant = $request->attributes->get('merchant');

        $data = $request->validate([
            'expected_count' => ['required', 'integer', 'min:1', 'max:5000'],
            'scheduled_at'   => ['nullable', 'date', 'after_or_equal:today'],
            'address'        => ['nullable', 'string', 'max:255'],
            'landmark'       => ['nullable', 'string', 'max:255'],
            'contact_phone'  => ['nullable', 'string', 'regex:/^07[0-9]{9}$/'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ], [], [
            'expected_count' => 'عدد الطرود',
            'scheduled_at'   => 'الموعد',
            'contact_phone'  => 'هاتف التواصل',
        ]);

        // طلب معلّق قائم يكفي — طلبان مفتوحان يُربكان التوزيع الصباحي
        $open = PickupRequest::where('merchant_id', $merchant->id)
            ->whereIn('status', ['pending', 'assigned'])
            ->exists();

        if ($open) {
            return back()->withErrors([
                'expected_count' => 'عندك طلب استلام مفتوح. عدّل عدده أو انتظر وصول المندوب.',
            ])->withInput();
        }

        $pickup = PickupRequest::create([
            'merchant_id'    => $merchant->id,
            'branch_id'      => $merchant->branch_id,
            'number'         => $sequences->next('pickup_request'),
            'status'         => 'pending',
            'expected_count' => $data['expected_count'],
            'address'        => $data['address'] ?? $merchant->address,
            'landmark'       => $data['landmark'] ?? $merchant->landmark,
            'contact_phone'  => $data['contact_phone'] ?? $merchant->phone,
            'requested_at'   => now(),
            'scheduled_at'   => $data['scheduled_at'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'created_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', "أُرسل طلب استلام برقم {$pickup->number}.");
    }
}
