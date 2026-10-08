<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Cash\MerchantAdvances;
use App\Http\Controllers\Controller;
use App\Models\CashBox;
use App\Models\Merchant;
use App\Models\MerchantAdvance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * «سلف التجّار» (docs/plan/38): تُعطى من صندوق، وتُستردّ من كشوف التاجر حين تُقفَل،
 * أو يسدّدها نقداً.
 */
class MerchantAdvanceController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['open', 'repaid', 'all'], true) ? $request->query('status') : 'open';

        $merchants = fn () => Merchant::withTrashed()->visibleTo($user)->select('id')
            ->where(fn (Builder $w) => $w->where('business_name', 'like', "%{$q}%")
                ->orWhere('code', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"));

        $advances = MerchantAdvance::visibleTo($user)
            ->with(['merchant:id,business_name,code', 'cashBox:id,name', 'user:id,name',
                'recoveries' => fn ($r) => $r->with('settlement:id,code')->orderBy('id')])
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->whereIn('merchant_id', $merchants()))
            ->when($request->integer('merchant_id'), fn ($w, $id) => $w->where('merchant_id', $id))
            ->orderByRaw("case status when 'open' then 0 else 1 end")
            ->orderByDesc('id')
            ->paginate(config('zajel.per_page'))
            ->withQueryString();

        $open = MerchantAdvance::visibleTo($user)->open();

        return view('tenant.money.advances', [
            'advances'    => $advances,
            'filters'     => ['q' => $q, 'status' => $status],
            'outstanding' => (int) (clone $open)->selectRaw('coalesce(sum(amount - recovered), 0) as v')->value('v'),
            'debtors'     => (int) (clone $open)->distinct()->count('merchant_id'),
            'merchants'   => Merchant::visibleTo($user)->where('status', 'active')->orderBy('business_name')
                ->get(['id', 'business_name', 'code']),
            'boxes'       => CashBox::payableBy($user)->orderByRaw('user_id is null')->orderBy('name')->get(['id', 'name', 'balance', 'user_id']),
            'defaultBox'  => CashBox::forActor($user, $user->branch_id)?->id,
        ]);
    }

    public function store(Request $request, MerchantAdvances $advances): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'integer'],
            'amount'      => ['required', 'integer', 'min:1', 'max:1000000000'],
            'cash_box_id' => ['required', 'integer'],
            'note'        => ['nullable', 'string', 'max:255'],
        ], [], ['merchant_id' => 'التاجر', 'amount' => 'المبلغ', 'cash_box_id' => 'الصندوق', 'note' => 'الملاحظة']);

        [$merchant, $box] = $this->resolve($request, $data);

        $advance = $advances->give($merchant, (int) $data['amount'], $box, $request->user(), $data['note'] ?? null);

        return redirect()->route('merchant-advances.index')->with('success',
            'أُعطيت '.$merchant->business_name.' سلفة '.number_format($advance->amount).' د.ع من '.$box->name
            ." ({$advance->number}). تُخصم من كشوفه القادمة حتى تُسدَّد.");
    }

    public function repay(Request $request, MerchantAdvances $advances): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id'  => ['required', 'integer'],
            'repay_amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'cash_box_id'  => ['required', 'integer'],
            'note'         => ['nullable', 'string', 'max:255'],
        ], [], ['merchant_id' => 'التاجر', 'repay_amount' => 'المبلغ', 'cash_box_id' => 'الصندوق', 'note' => 'الملاحظة']);

        [$merchant, $box] = $this->resolve($request, $data);

        $paid = $advances->repay($merchant, (int) $data['repay_amount'], $box, $request->user(), $data['note'] ?? null);

        return redirect()->route('merchant-advances.index')->with('success',
            'قُبض من '.$merchant->business_name.' '.number_format($paid).' د.ع سداداً لسلفه في '.$box->name
            .'. بقي عليه '.number_format(MerchantAdvances::outstanding($merchant)).' د.ع.');
    }

    /** @return array{0: Merchant, 1: CashBox} */
    protected function resolve(Request $request, array $data): array
    {
        $merchant = Merchant::visibleTo($request->user())->find($data['merchant_id']);
        $box = CashBox::payableBy($request->user())->find($data['cash_box_id']);

        if (! $merchant) {
            throw ValidationException::withMessages(['merchant_id' => 'التاجر غير موجود.']);
        }

        if (! $box) {
            throw ValidationException::withMessages(['cash_box_id' => 'اختر صندوقك أو صندوق فرعك.']);
        }

        return [$merchant, $box];
    }
}
