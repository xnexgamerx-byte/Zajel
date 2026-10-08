<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Settlements\BuildMerchantSettlement;
use App\Actions\Settlements\DeleteDraftSettlement;
use App\Actions\Settlements\EditDraftSettlement;
use App\Actions\Settlements\PayMerchantSettlement;
use App\Http\Controllers\Controller;
use App\Models\CashBox;
use App\Models\Merchant;
use App\Models\MerchantSettlement;
use App\Support\Money\SettlementFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MerchantSettlementController extends Controller
{
    /** من قام بالكشف: بناه، أو أقفله، أو دفعه */
    private const ACTORS = ['created_by_user_id', 'confirmed_by_user_id', 'paid_by_user_id'];

    public function index(Request $request): View
    {
        $filters = SettlementFilters::fromRequest($request);
        $match = fn ($q) => $filters->matchParty($q, ['business_name', 'owner_name']);

        return view('tenant.settlements.merchants.index', [
            'filters'     => $filters,
            'actors'      => SettlementFilters::actors(MerchantSettlement::visibleTo($request->user()), self::ACTORS),
            'settlements' => $filters->apply(
                MerchantSettlement::visibleTo($request->user())->with(['merchant:id,business_name,code', 'confirmedBy:id,name', 'paidBy:id,name']),
                'merchant_id', $match(Merchant::withTrashed()), self::ACTORS,
            )
                ->when($request->query('merchant_id'), fn ($q, $m) => $q->where('merchant_id', $m))
                ->latest('id')
                ->paginate(config('zajel.per_page'))
                ->withQueryString(),

            'pending' => $match(Merchant::query())
                ->visibleTo($request->user())
                ->where('balance', '!=', 0)
                ->orderByDesc('balance')
                ->get(),
        ]);
    }

    public function store(Request $request, BuildMerchantSettlement $build): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'integer'],
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date', 'after_or_equal:from'],
        ], [], ['merchant_id' => 'التاجر']);

        $merchant = Merchant::visibleTo($request->user())->find($data['merchant_id']);

        if (! $merchant) {
            return back()->withErrors(['merchant_id' => 'التاجر غير موجود.']);
        }

        $settlement = $build->handle($merchant, $request->user(), [
            'from' => $data['from'] ?? null,
            'to'   => $data['to'] ?? null,
        ]);

        return redirect()
            ->route('settlements.merchants.show', $settlement)
            ->with('success', "فُتح كشف {$settlement->code}، فيه ".\App\Support\Arabic::shipments((int) $settlement->shipments_count).'.');
    }

    public function show(Request $request, MerchantSettlement $settlement, EditDraftSettlement $edit): View
    {
        $settlement->load('merchant');

        // المسودّة تُعدَّل لمن يملك التسوية: يُحاسَب على بعضها، ويُضاف إليها ما ينتظر خارجها
        $editable = $settlement->status === 'draft' && $request->user()->can('money.settle');

        // الدفع النقدي يخرج من صندوق: يُرى رصيده قبل الضغط، لا بعد أن يُرفض — لمن يدفع وحده
        $paying = $settlement->status === 'confirmed' && $request->user()->can('money.pay');

        return view('tenant.settlements.merchants.show', [
            'settlement' => $settlement,
            'boxes'      => $paying ? CashBox::payableBy($request->user())
                ->orderByRaw("case when type = 'main' then 0 else 1 end")->orderBy('name')
                ->get(['id', 'name', 'balance', 'user_id']) : collect(),
            'defaultBox' => $paying ? PayMerchantSettlement::defaultBox($settlement, $request->user()) : null,
            // كشف تاجرٍ كبير قد يحمل آلاف السطور: تُعرض صفحةً صفحة، والمجاميع من الكشف نفسه
            'lines'      => $settlement->lines()->with('shipment.governorate:id,name_ar')
                ->orderBy('id')->paginate(100),
            'editable'     => $editable,
            'addable'      => $editable ? $edit->addable($settlement) : collect(),
            'addableCount' => $editable ? $edit->addableCount($settlement) : 0,
        ]);
    }

    public function confirm(Request $request, MerchantSettlement $settlement, PayMerchantSettlement $action): RedirectResponse
    {
        $data = $request->validate([
            'notes'          => ['nullable', 'string', 'max:500'],
            // «حاسب التاجر على المحدَّد»: والبقية تبقى في المسودّة
            'shipment_ids'   => ['nullable', 'array', 'max:5000'],
            'shipment_ids.*' => ['integer'],
        ]);

        $settled = $action->confirm($settlement, $request->user(), $data['notes'] ?? null, $data['shipment_ids'] ?? null);

        // يُدفع من صفحة الكشف الجديد، والبقية في المسودّة
        if ($settled->isNot($settlement)) {
            $left = (int) $settlement->fresh()->shipments_count;

            return redirect()->route('settlements.merchants.show', $settled)->with('success',
                "أُقفِل كشف {$settled->code} بالمحدَّد: ".\App\Support\Arabic::shipments((int) $settled->shipments_count)
                .'. سجّل الدفع بعد تحويل المبلغ. وبقيت '.\App\Support\Arabic::shipments($left)." في المسودّة {$settlement->code}.");
        }

        return back()->with('success', "أُقفِل كشف {$settlement->code}. سجّل الدفع بعد تحويل المبلغ.");
    }

    public function pay(Request $request, MerchantSettlement $settlement, PayMerchantSettlement $action): RedirectResponse
    {
        $data = $request->validate([
            'payout_method'    => ['required', Rule::in(array_keys(\App\Models\Merchant::PAYOUT_METHODS))],
            'payout_reference' => ['nullable', 'string', 'max:120'],
            'cash_box_id'      => ['nullable', 'integer'],
        ], [], ['payout_method' => 'طريقة الدفع', 'payout_reference' => 'رقم الحوالة', 'cash_box_id' => 'الصندوق']);

        $box = null;

        // الحوالة لا تمسّ درجاً: لا يُسأل عن الصندوق المختار في النموذج
        if ($data['payout_method'] === 'cash' && filled($data['cash_box_id'] ?? null)) {
            $box = CashBox::payableBy($request->user())->find($data['cash_box_id']);

            if (! $box) {
                return back()->withErrors(['cash_box_id' => 'اختر صندوقاً مفعّلاً تدفع منه.'])->withInput();
            }
        }

        // رصيدٌ لا يكفي يرجع برسالته (InsufficientCash) ولا يُكتب شيء
        $action->pay($settlement, $request->user(), $data['payout_method'], $data['payout_reference'] ?? null, $box);

        return back()->with('success', "سُجِّل دفع كشف {$settlement->code}.");
    }

    /** حذف المسودّة: لا أثر لها في الحساب، وشحناتها تدخل الكشف التالي كما هي */
    public function destroy(Request $request, MerchantSettlement $settlement, DeleteDraftSettlement $delete): RedirectResponse
    {
        $delete->handle($settlement, $request->user());

        return redirect()
            ->route('settlements.merchants.index')
            ->with('success', "حُذف كشف {$settlement->code}. شحناته تدخل الكشف التالي كما هي.");
    }

    /** إضافة شحناتٍ تنتظر التسوية إلى المسودّة */
    public function addLines(Request $request, MerchantSettlement $settlement, EditDraftSettlement $edit): RedirectResponse
    {
        $added = $edit->add($settlement, $this->picked($request), $request->user());

        return back()->with('success', 'أُضيفت إلى كشف '.$settlement->code.': '.\App\Support\Arabic::shipments(count($added)).'.');
    }

    /** @return list<int> */
    protected function picked(Request $request): array
    {
        return $request->validate([
            'shipment_ids'   => ['required', 'array', 'min:1', 'max:5000'],
            'shipment_ids.*' => ['integer'],
        ], ['shipment_ids.required' => 'اختر الشحنات أوّلاً.'], ['shipment_ids' => 'الشحنات'])['shipment_ids'];
    }
}
