<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Pickups\AccruePickupShare;
use App\Actions\Pickups\PayPickupCommission;
use App\Http\Controllers\Controller;
use App\Models\CashBox;
use App\Models\Courier;
use App\Models\PickupShare;
use App\Services\Reports\ReportPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * حساب مندوب الاستلام وأرباحه واعتراضاته.
 *
 * دور مستقلّ عن مندوب التوصيل: يجمع طروداً لا أموالاً، فحسابه عمولة
 * صافية لا تسوية نقد، وله حقّ الاعتراض على احتساب حصّته.
 */
class PickupAgentController extends Controller
{
    public function __construct(
        protected AccruePickupShare $shares,
        protected PayPickupCommission $payout,
    ) {}

    /** حسابات مندوبي الاستلام — كم استحقّ كلٌّ ومتى قُبض. */
    public function index(Request $request): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        $agents = Courier::picking()
            ->orderByDesc('commission_balance')
            ->get()
            ->map(function (Courier $courier) use ($from, $to) {
                $courier->setAttribute('period', PickupShare::query()
                    ->where('courier_id', $courier->id)
                    ->whereBetween('created_at', [$from, $to])
                    ->selectRaw('count(*) as requests, sum(shipments_count) as parcels,
                                 sum(amount + adjustment) as earned')
                    ->toBase()
                    ->first());

                return $courier;
            });

        return view('tenant.pickup_agents.index', [
            'period'     => $period,
            'agents'     => $agents,
            'boxes'      => CashBox::active()->orderBy('name')->get(['id', 'name', 'balance']),
            'objections' => PickupShare::objected()->count(),
        ]);
    }

    public function show(Request $request, Courier $courier): View
    {
        $period = ReportPeriod::fromRequest($request);
        [$from, $to] = $period->bounds();

        return view('tenant.pickup_agents.show', [
            'period'  => $period,
            'courier' => $courier,
            'shares'  => PickupShare::with('pickupRequest:id,number,merchant_id')
                ->where('courier_id', $courier->id)
                ->whereBetween('created_at', [$from, $to])
                ->latest('id')
                ->paginate(config('zajel.per_page'))
                ->withQueryString(),
            'boxes'   => CashBox::active()->orderBy('name')->get(['id', 'name', 'balance']),
        ]);
    }

    public function pay(Request $request, Courier $courier): RedirectResponse
    {
        $data = $request->validate([
            'cash_box_id' => ['nullable', 'integer'],
            'note'        => ['nullable', 'string', 'max:255'],
        ], [], ['cash_box_id' => 'الصندوق']);

        $box = empty($data['cash_box_id']) ? null : CashBox::active()->find($data['cash_box_id']);

        $paid = $this->payout->handle($courier, $request->user(), $box, $data['note'] ?? null);

        return back()->with('success', "دُفعت عمولة {$courier->name}: ".number_format($paid).' دينار.');
    }

    /** ٢ — اعتراضات حصص الاستلام. */
    public function objections(): View
    {
        return view('tenant.pickup_agents.objections', [
            'open'     => PickupShare::objected()
                ->with(['courier:id,name', 'pickupRequest:id,number'])
                ->orderBy('objected_at')
                ->get(),
            'resolved' => PickupShare::whereIn('status', ['adjusted', 'rejected'])
                ->with(['courier:id,name', 'pickupRequest:id,number'])
                ->latest('resolved_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function resolve(Request $request, PickupShare $share): RedirectResponse
    {
        $data = $request->validate([
            'decision'     => ['required', 'in:accept,reject'],
            'agreed_count' => ['required_if:decision,accept', 'nullable', 'integer', 'min:0', 'max:5000'],
            'note'         => ['required', 'string', 'max:255'],
        ], [], ['agreed_count' => 'العدد المُقَرّ', 'note' => 'القرار']);

        $this->shares->resolve(
            $share,
            $data['decision'] === 'accept' ? (int) $data['agreed_count'] : null,
            $data['note'],
            $request->user(),
        );

        return back()->with('success', $data['decision'] === 'accept'
            ? 'قُبل الاعتراض وقُيّد فرقه في حساب المندوب.'
            : 'رُفض الاعتراض وسُجّل سببه.');
    }
}
