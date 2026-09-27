<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Governorate;
use App\Models\GovernorateSetting;
use App\Models\PriceList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * «إعدادات المحافظة» كما في المعتاد: صفٌّ لكل محافظة — نشطةٌ لهذه الشركة أم
 * لا، وترتيبها في القوائم، و«أجرة المندوب» إلى مركزها وإلى أقضيتها.
 *
 * ومبلغا الشحن (للمركز وللأقضية) يُقرآن هنا من التسعيرة الافتراضية ويُحرَّران
 * فيها — مكانٌ واحد لكل رقم.
 */
class GovernorateSettingController extends Controller
{
    public function index(): View
    {
        $default = PriceList::where('is_default', true)->first();

        return view('tenant.governorate-settings.index', [
            'governorates' => Governorate::where('is_active', true)->orderedForCompany()->get(),
            'settings'     => GovernorateSetting::all()->keyBy('governorate_id'),
            'default'      => $default,
            'rules'        => $default
                ? $default->rules()->whereNull('to_city_id')->where('weight_from_grams', 0)->get()->keyBy(fn ($r) => $r->to_governorate_id ?? 0)
                : collect(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rows'                          => ['required', 'array'],
            'rows.*.is_active'              => ['nullable', 'boolean'],
            'rows.*.sort_order'             => ['nullable', 'integer', 'min:0', 'max:999'],
            'rows.*.courier_fee'            => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'rows.*.courier_fee_peripheral' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ], [], [
            'rows.*.sort_order'  => 'الترتيب',
            'rows.*.courier_fee' => 'أجرة المندوب',
        ]);

        $known = Governorate::where('is_active', true)->pluck('id')->flip();
        $nullable = fn ($v) => filled($v) ? (int) $v : null;

        DB::transaction(function () use ($data, $known, $nullable) {
            foreach ($data['rows'] as $governorateId => $row) {
                if (! $known->has((int) $governorateId)) {
                    continue;
                }

                $values = [
                    'is_active'              => (bool) ($row['is_active'] ?? false),
                    'sort_order'             => $nullable($row['sort_order'] ?? null),
                    'courier_fee'            => $nullable($row['courier_fee'] ?? null),
                    'courier_fee_peripheral' => $nullable($row['courier_fee_peripheral'] ?? null),
                ];

                // كالافتراض في كل شيء: لا صفّ لها
                if ($values['is_active'] && $values['sort_order'] === null
                    && $values['courier_fee'] === null && $values['courier_fee_peripheral'] === null) {
                    GovernorateSetting::where('governorate_id', $governorateId)->delete();

                    continue;
                }

                GovernorateSetting::updateOrCreate(['governorate_id' => (int) $governorateId], $values);
            }
        });

        return back()->with('success', 'حُفظت إعدادات المحافظات.');
    }
}
