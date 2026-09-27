<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\CitySetting;
use App\Models\Courier;
use App\Models\CourierZone;
use App\Models\Governorate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «المناطق» كما في المعتاد: لكل منطقةٍ من محافظةٍ «أجرة النقل الخاصّة بها»
 * و«طرفية؟»، ومن يغطّيها من المندوبين — وفلتر «غير مسنود لمندوب».
 *
 * صفحاتٌ من ستّين منطقة: بغداد وحدها ثلاثمئة وستّون، ونموذجٌ بها كلّها
 * يقارب حدّ الحقول في الطلب الواحد.
 */
class AreaController extends Controller
{
    public const PER_PAGE = 60;

    public function index(Request $request): View
    {
        $governorates = Governorate::offered()->get(['id', 'name_ar', 'code']);
        $governorate = $governorates->firstWhere('id', $request->integer('governorate_id'))
            ?? $governorates->firstWhere('code', 'BGD')
            ?? $governorates->first();

        abort_if($governorate === null, 404);

        $coverage = CourierZone::where('governorate_id', $governorate->id)->with('courier:id,name,status')->get();
        $courierId = $request->integer('courier_id') ?: null;
        $wholeGovernorate = $coverage->whereNull('city_id');

        $cities = City::query()
            ->where('governorate_id', $governorate->id)
            ->where('is_active', true)
            ->when(trim((string) $request->query('q')), fn ($q, $term) => $q->where('name_ar', 'like', "%{$term}%"))
            // مندوبٌ بعينه: مناطقه — والمحافظة كلّها إن كان يغطّيها
            ->when($courierId && ! $wholeGovernorate->contains('courier_id', $courierId), fn ($q) => $q->whereIn('id',
                CourierZone::where('courier_id', $courierId)->whereNotNull('city_id')->select('city_id')))
            ->when($request->boolean('unassigned'), fn ($q) => $q->whereNotIn('id',
                CourierZone::where('governorate_id', $governorate->id)->whereNotNull('city_id')->select('city_id')))
            ->when($request->query('kind') === 'peripheral', fn ($q) => $q->whereIn('id',
                CitySetting::where('is_peripheral', true)->select('city_id')))
            ->when($request->query('kind') === 'fee', fn ($q) => $q->whereIn('id',
                CitySetting::whereNotNull('delivery_fee')->select('city_id')))
            ->orderBy('name_ar')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('tenant.areas.index', [
            'governorates'     => $governorates,
            'governorate'      => $governorate,
            'cities'           => $cities,
            'settings'         => CitySetting::whereIn('city_id', $cities->pluck('id'))->get()->keyBy('city_id'),
            'byCity'           => $coverage->whereNotNull('city_id')->groupBy('city_id'),
            'wholeGovernorate' => $wholeGovernorate,
            'couriers'         => Courier::delivering()->orderBy('name')->get(['id', 'name']),
            'peripheralCount'  => CitySetting::where('is_peripheral', true)
                ->whereIn('city_id', City::where('governorate_id', $governorate->id)->select('id'))->count(),
        ]);
    }

    /** حفظ صفحة المناطق كما هي: الأجرة الخاصّة والطرفية لكلٍّ */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'governorate_id'        => ['required', 'integer'],
            'rows'                  => ['required', 'array', 'max:'.self::PER_PAGE],
            'rows.*.delivery_fee'   => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'rows.*.is_peripheral'  => ['nullable', 'boolean'],
        ], [], ['rows.*.delivery_fee' => 'أجرة المنطقة']);

        $valid = City::where('governorate_id', $data['governorate_id'])
            ->whereIn('id', array_map('intval', array_keys($data['rows'])))
            ->pluck('id')->flip();

        DB::transaction(function () use ($data, $valid) {
            foreach ($data['rows'] as $cityId => $row) {
                if (! $valid->has((int) $cityId)) {
                    continue;
                }

                $fee = filled($row['delivery_fee'] ?? null) ? (int) $row['delivery_fee'] : null;
                $peripheral = (bool) ($row['is_peripheral'] ?? false);

                // منطقةٌ بلا أجرةٍ خاصّة وليست طرفية: كمحافظتها، فلا صفّ لها
                if ($fee === null && ! $peripheral) {
                    CitySetting::where('city_id', $cityId)->delete();

                    continue;
                }

                CitySetting::updateOrCreate(['city_id' => (int) $cityId], ['delivery_fee' => $fee, 'is_peripheral' => $peripheral]);
            }
        });

        return back()->with('success', 'حُفظت أجور المناطق وأطرافها. الشحنات الجديدة تُسعَّر بها فوراً.');
    }

    /** «تعديل المناطق الطرفية وغير الطرفية» للمحافظة كلّها دفعةً واحدة */
    public function peripheral(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'governorate_id' => ['required', 'integer'],
            'mode'           => ['required', Rule::in(['all', 'none'])],
        ]);

        $governorate = Governorate::findOrFail($data['governorate_id']);
        $cityIds = City::where('governorate_id', $governorate->id)->where('is_active', true)->pluck('id');

        DB::transaction(function () use ($data, $cityIds) {
            if ($data['mode'] === 'all') {
                $existing = CitySetting::whereIn('city_id', $cityIds)->pluck('city_id')->flip();
                CitySetting::whereIn('city_id', $cityIds)->update(['is_peripheral' => true]);

                foreach ($cityIds->reject(fn ($id) => $existing->has($id)) as $cityId) {
                    CitySetting::create(['city_id' => $cityId, 'is_peripheral' => true]);
                }

                return;
            }

            CitySetting::whereIn('city_id', $cityIds)->update(['is_peripheral' => false]);
            CitySetting::whereIn('city_id', $cityIds)->whereNull('delivery_fee')->delete();
        });

        return back()->with('success', $data['mode'] === 'all'
            ? "صارت مناطق {$governorate->name_ar} كلّها طرفية."
            : "صارت مناطق {$governorate->name_ar} كلّها مركزاً.");
    }
}
