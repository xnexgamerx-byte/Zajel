<?php

namespace App\Http\Controllers\Courier;

use App\Actions\Pickups\AccruePickupShare;
use App\Http\Controllers\Controller;
use App\Models\PickupPayout;
use App\Models\PickupShare;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * حصص المندوب واعتراضه عليها.
 *
 * الحقّ في الاعتراض لا معنى له إن لم يكن في يد صاحبه: المندوب يرى ما
 * احتُسب له طلباً طلباً، ويعترض من هاتفه لا بمكالمة تُنسى.
 */
class ShareController extends Controller
{
    public function index(Request $request): View
    {
        $courier = $request->attributes->get('courier');

        return view('courier.shares', [
            'courier' => $courier,
            'payouts' => $courier->payouts()->latest('id')->limit(20)->get(),
            'shares'  => PickupShare::with('pickupRequest:id,number')
                ->where('courier_id', $courier->id)
                ->latest('id')
                ->limit(50)
                ->get(),
        ]);
    }

    /** «استلمت»: مندوب الاستلام يؤكّد دفعة ربحه — ومن لم يؤكّد يُعرف */
    public function confirm(Request $request, PickupPayout $payout): RedirectResponse
    {
        abort_unless((int) $payout->courier_id === (int) $request->attributes->get('courier')->id, 404);

        $confirmed = PickupPayout::whereKey($payout->id)->whereNull('confirmed_at')->update(['confirmed_at' => now()]);

        return $confirmed
            ? back()->with('success', "أكّدت استلام الدفعة {$payout->number}.")
            : back()->withErrors(['payout' => "الدفعة {$payout->number} مؤكَّدة سلفاً."]);
    }

    public function object(Request $request, PickupShare $share, AccruePickupShare $action): RedirectResponse
    {
        $courier = $request->attributes->get('courier');

        abort_unless($share->courier_id === $courier->id, 404);

        $data = $request->validate([
            'claimed_count' => ['required', 'integer', 'min:0', 'max:5000'],
            'reason'        => ['required', 'string', 'max:255'],
        ], [], ['claimed_count' => 'العدد الذي جمعته', 'reason' => 'السبب']);

        $action->object($share, (int) $data['claimed_count'], $data['reason']);

        return back()->with('success', 'سُجّل اعتراضك. سيُبتّ فيه من إدارة الفرع.');
    }
}
