<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Governorate;
use App\Models\Merchant;
use App\Models\PriceList;
use App\Models\PriceListRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * التسعيرات. "بلا تسعير لا محاسبة" — وكانت حتى الآن تُزرَع ولا تُحرَّر.
 *
 * الشاشة مصفوفة: صفّ لكل محافظة وصفّ عام يغطّي ما لم يُذكر، تُحفَظ دفعة
 * واحدة. تسعيرة العراق كلّه ١٨ صفاً، وتحريرها صفّاً صفّاً يعني ١٨ حفظاً
 * و١٨ فرصة لنسيان واحد.
 */
class PriceListController extends Controller
{
    public function index(): View
    {
        return view('tenant.pricing.index', [
            'lists' => PriceList::withCount('rules')->orderByDesc('is_default')->orderBy('name')->get(),
            'usage' => Merchant::query()
                ->whereNotNull('price_list_id')
                ->selectRaw('price_list_id, count(*) as c')
                ->groupBy('price_list_id')
                ->pluck('c', 'price_list_id'),
            'defaultUsers' => Merchant::whereNull('price_list_id')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:160',
                            Rule::unique('price_lists', 'name')->where('company_id', $request->user()->company_id)],
            'copy_from' => ['nullable', 'integer'],
        ], [], ['name' => 'الاسم']);

        $list = DB::transaction(function () use ($data) {
            $list = PriceList::create(['name' => $data['name'], 'is_active' => true]);

            // النسخ من تسعيرة قائمة أسرع بكثير من إعادة إدخال 18 صفاً
            if ($source = PriceList::find($data['copy_from'] ?? null)) {
                foreach ($source->rules as $rule) {
                    PriceListRule::create(
                        collect($rule->toArray())
                            ->except(['id', 'price_list_id', 'created_at', 'updated_at'])
                            ->all() + ['price_list_id' => $list->id]
                    );
                }
            }

            return $list;
        });

        return redirect()->route('pricing.edit', $list)->with('success', "أُنشئت تسعيرة {$list->name}.");
    }

    public function edit(PriceList $pricing): View
    {
        $rules = $pricing->rules()->get()->keyBy(fn (PriceListRule $r) => $r->to_governorate_id ?? 0);

        return view('tenant.pricing.edit', [
            'list'         => $pricing,
            'governorates' => Governorate::where('is_active', true)->orderBy('sort_order')->get(),
            'rules'        => $rules,
        ]);
    }

    /**
     * حفظ المصفوفة كاملة.
     *
     * الصفّ الفارغ (بلا أجرة توصيل) يُحذف بدل أن يُحفظ بصفر: قاعدة بصفر
     * تعني "التوصيل مجاني هنا"، وهذا غير "لم تُسعَّر هذه المحافظة بعد".
     */
    public function update(Request $request, PriceList $pricing): RedirectResponse
    {
        $data = $request->validate([
            'name'                     => ['required', 'string', 'max:160'],
            'is_default'               => ['nullable', 'boolean'],
            'is_active'                => ['nullable', 'boolean'],
            'rows'                     => ['required', 'array'],
            'rows.*.delivery_fee'      => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'rows.*.return_fee'        => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'rows.*.replacement_fee'   => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'rows.*.extra_kg_fee'      => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'rows.*.cod_fee_flat'      => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'rows.*.cod_fee_percent'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'weight_to_grams'          => ['required', 'integer', 'min:100', 'max:1000000'],
        ], [], [
            'name' => 'الاسم', 'weight_to_grams' => 'حد الوزن',
            'rows.*.delivery_fee' => 'أجرة التوصيل',
        ]);

        $governorateIds = Governorate::pluck('id')->all();

        DB::transaction(function () use ($request, $pricing, $data, $governorateIds) {
            $pricing->update([
                'name'      => $data['name'],
                'is_active' => $request->boolean('is_active', true),
            ]);

            if ($request->boolean('is_default')) {
                PriceList::whereKeyNot($pricing->id)->update(['is_default' => false]);
                $pricing->forceFill(['is_default' => true])->save();
            }

            foreach ($data['rows'] as $key => $row) {
                $governorateId = (int) $key ?: null;

                if ($governorateId !== null && ! in_array($governorateId, $governorateIds, true)) {
                    continue;
                }

                $match = [
                    'price_list_id'     => $pricing->id,
                    'to_governorate_id' => $governorateId,
                    'to_city_id'        => null,
                    'weight_from_grams' => 0,
                ];

                if (blank($row['delivery_fee'] ?? null)) {
                    PriceListRule::where($match)->delete();

                    continue;
                }

                PriceListRule::updateOrCreate($match, [
                    'weight_to_grams'  => (int) $data['weight_to_grams'],
                    'delivery_fee'     => (int) $row['delivery_fee'],
                    'return_fee'       => (int) ($row['return_fee'] ?? 0),
                    'replacement_fee'  => (int) ($row['replacement_fee'] ?? 0),
                    'extra_kg_fee'     => (int) ($row['extra_kg_fee'] ?? 0),
                    'cod_fee_flat'     => (int) ($row['cod_fee_flat'] ?? 0),
                    'cod_fee_percent'  => (float) ($row['cod_fee_percent'] ?? 0),
                    // المحافظة أخصّ من القاعدة العامة، فتغلبها عند التطابق
                    'priority'         => $governorateId ? 1 : 0,
                    'is_active'        => true,
                ]);
            }
        });

        return back()->with('success', 'حُفظت التسعيرة. الشحنات الجديدة تُسعَّر بها فوراً.');
    }
}
