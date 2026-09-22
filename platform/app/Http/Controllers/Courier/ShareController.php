<?php

namespace App\Http\Controllers\Courier;

use App\Actions\Pickups\AccruePickupShare;
use App\Http\Controllers\Controller;
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
            'shares'  => PickupShare::with('pickupRequest:id,number')
                ->where('courier_id', $courier->id)
                ->latest('id')
                ->limit(50)
                ->get(),
        ]);
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
