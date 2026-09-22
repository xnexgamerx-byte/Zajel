<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Courier;
use App\Models\CourierZone;
use App\Models\Governorate;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * توزيع المندوبين على المناطق.
 *
 * الجدول موجود منذ الأساس ويُقال عنه إنه «يُستخدم في التوزيع التلقائي»
 * — ولم يكن يملؤه أحد، فكان التوزيع الصباحي اختياراً يدوياً من قائمة
 * كل المندوبين. والمنطقة بلا مندوب هي ما يجعل شحنةً تنام في المخزن.
 */
class ZoneController extends Controller
{
    public function index(Request $request): View
    {
        $governorates = Governorate::where('is_active', true)->orderBy('name_ar')->get();
        $couriers = Courier::delivering()->orderBy('name')->get();

        $zones = CourierZone::query()
            ->with('courier:id,name,status')
            ->get()
            ->groupBy('governorate_id');

        // ما ينتظر في كل محافظة الآن — المنطقة المكشوفة تُرى بالأرقام لا بالحدس
        $waiting = Shipment::query()
            ->whereIn('status', ShipmentStatus::openValues())
            ->selectRaw('governorate_id, count(*) as total')
            ->groupBy('governorate_id')
            ->toBase()
            ->pluck('total', 'governorate_id');

        return view('tenant.zones.index', [
            'governorates' => $governorates,
            'couriers'     => $couriers,
            'zones'        => $zones,
            'waiting'      => $waiting,
            'uncovered'    => $governorates->filter(fn ($g) => ($zones[$g->id] ?? collect())->isEmpty()
                && ($waiting[$g->id] ?? 0) > 0),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'courier_id'     => ['required', 'integer'],
            'governorate_id' => ['required', 'integer'],
            'city_id'        => ['nullable', 'integer'],
        ], [], ['courier_id' => 'المندوب', 'governorate_id' => 'المحافظة']);

        $courier = Courier::delivering()->find($data['courier_id']);
        $governorate = Governorate::find($data['governorate_id']);

        if (! $courier || ! $governorate) {
            return back()->withErrors(['courier_id' => 'اختر مندوب توصيل ومحافظة موجودَين.']);
        }

        if ($data['city_id'] && ! City::where('governorate_id', $governorate->id)->whereKey($data['city_id'])->exists()) {
            return back()->withErrors(['city_id' => 'هذه المنطقة ليست في المحافظة المختارة.']);
        }

        CourierZone::firstOrCreate([
            'courier_id'     => $courier->id,
            'governorate_id' => $governorate->id,
            'city_id'        => $data['city_id'] ?: null,
        ]);

        return back()->with('success', "أُسندت {$governorate->name_ar} إلى {$courier->name}.");
    }

    public function destroy(CourierZone $zone): RedirectResponse
    {
        $zone->delete();

        return back()->with('success', 'أُلغي الإسناد.');
    }
}
