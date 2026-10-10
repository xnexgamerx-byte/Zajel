<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\SendOutForDelivery;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Courier;
use App\Models\CourierZone;
use App\Models\Governorate;
use App\Models\Shipment;
use App\Support\Arabic;
use App\Support\ScanCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * «توزيع بالمسح» (docs/plan/50): لكلّ محافظةٍ توزيعها — يُمسح الوصل فيدخل الجدول إن كانت
 * وجهته المحافظة المختارة وحدها، ويُجمَع بمنطقته، ولكلّ منطقةٍ مندوبها: يُقترح من مناطق
 * المناديب ويُبدَّل من القائمة. ثم فعلٌ واحد يُخرج كلّ شحنةٍ مع مندوب منطقتها.
 *
 * المسح لا يغيّر شيئاً حتى «وزّع»: الجدول في المتصفّح كشاشة الاستلام.
 */
class ShipmentDistributeController extends Controller
{
    public function index(Request $request): View
    {
        $governorates = Governorate::where('is_active', true)->orderedForCompany()->get(['id', 'name_ar']);
        $governorate = $governorates->firstWhere('id', (int) $request->query('governorate'))
            ?? $governorates->firstWhere('id', $this->home($request))
            ?? $governorates->first();

        return view('tenant.shipments.distribute', [
            'governorates' => $governorates,
            'governorate'  => $governorate,
            'couriers'     => $this->couriers($request, $governorate?->id),
        ]);
    }

    /** الوصل لهذا التوزيع: وجهته المحافظة المختارة، ومنطقته، ومندوبها المقترح */
    public function lookup(Request $request): JsonResponse
    {
        $typed = (string) $request->query('number');
        $governorateId = (int) $request->query('governorate');
        $scan = ScanCode::read($typed);

        if (! $scan->usable() || mb_strlen($typed) > ScanCode::MAX_INPUT) {
            return response()->json(['error' => 'اكتب رقم الوصل أو امسحه.'], 422);
        }

        $shipment = $scan->find(Shipment::query()
            ->with(['merchant:id,business_name', 'governorate:id,name_ar', 'city:id,name_ar', 'deliveryCourier:id,name'])
            ->visibleTo($request->user()));

        if (! $shipment) {
            return response()->json(['error' => $scan->notFound()], 404);
        }

        if ((int) $shipment->governorate_id !== $governorateId) {
            return response()->json(['error' => "{$shipment->number} وجهته {$shipment->governorate?->name_ar} — ليس من توزيع هذه المحافظة."], 422);
        }

        // المسلَّمة والملغاة وما يشبهها لا تُوزَّع: يُقال عند المسح لا بعد «وزّع»
        if ($shipment->isTerminal()) {
            return response()->json(['error' => "{$shipment->number} حالته «{$shipment->statusLabel()}» — لا يُوزَّع."], 422);
        }

        $phone = (string) $shipment->recipient_phone;

        return response()->json([
            'id'        => $shipment->id,
            'number'    => $shipment->number,
            'merchant'  => $shipment->merchant?->business_name,
            'status'    => $shipment->statusLabel(),
            'tone'      => $shipment->statusColor(),
            'amount'    => (int) $shipment->cod_amount,
            'area_id'   => (int) $shipment->city_id,
            'area'      => $shipment->city?->name_ar ?? 'بلا منطقة',
            'courier'   => $shipment->deliveryCourier?->name,
            'suggested' => $this->suggest($request, $shipment),
            'phone'     => str_repeat('•', max(0, mb_strlen($phone) - 3)).mb_substr($phone, -3),
            'url'       => route('shipments.show', $shipment),
        ]);
    }

    public function store(Request $request, SendOutForDelivery $sendOut): RedirectResponse
    {
        $data = $request->validate([
            'governorate_id' => ['required', 'integer'],
            'courier'        => ['required', 'array', 'min:1', 'max:500'],
            'courier.*'      => ['nullable', 'integer'],
        ], [], ['courier' => 'الوصولات']);

        $back = redirect()->route('shipments.distribute', ['governorate' => $data['governorate_id']]);

        // لكلّ وصلٍ مندوبه: [رقم الشحنة => رقم المندوب]
        $plan = collect($data['courier'])->mapWithKeys(fn ($c, $id) => [(int) $id => (int) $c]);

        if ($plan->contains(0)) {
            return $back->withErrors(['courier' => 'اختر مندوباً لكلّ منطقة قبل التوزيع.']);
        }

        $couriers = Courier::delivering()->active()->visibleTo($request->user())
            ->whereIn('id', $plan->unique()->values())->get()->keyBy('id');

        // ما لا يراه لا يوزَّع باسمه، وما وجهته محافظةٌ أخرى لا يدخل هذا التوزيع
        $shipments = Shipment::whereIn('id', $plan->keys())
            ->visibleTo($request->user())
            ->where('governorate_id', $data['governorate_id'])
            ->with('deliveryCourier:id,name')
            ->orderBy('id')
            ->get();

        $moved = 0;
        $skipped = [];

        foreach ($shipments->groupBy(fn (Shipment $s) => $plan[$s->id]) as $courierId => $group) {
            $courier = $couriers->get($courierId);

            if (! $courier) {
                foreach ($group as $s) {
                    $skipped[$s->number] = 'المندوب غير متاح';
                }

                continue;
            }

            $result = $sendOut->handle($group, $courier, $request->user(), 'توزيع بالمسح');
            $moved += count($result['moved']);
            $skipped += $result['skipped'];
        }

        foreach ($plan->keys()->diff($shipments->pluck('id')) as $missing) {
            $skipped['#'.$missing] = 'ليست من هذه المحافظة';
        }

        $message = $moved ? 'وُزّعت '.Arabic::shipments($moved).' على مناديبها.' : 'لم تُوزَّع أيّ شحنة.';

        if ($skipped) {
            $message .= ' تُخطّيت '.count($skipped).': '.collect($skipped)->take(6)
                ->map(fn ($reason, $number) => "{$number} ({$reason})")->implode('، ').(count($skipped) > 6 ? '…' : '');
        }

        return $moved ? $back->with('success', $message) : $back->withErrors(['courier' => $message]);
    }

    /** محافظة فرع الموظّف — والرئيسي بغداد في الغالب — ليفتح توزيعه هو أوّلاً */
    private function home(Request $request): ?int
    {
        $branch = Branch::find($request->user()->branch_id) ?? Branch::where('is_main', true)->first();

        return $branch?->governorate_id;
    }

    /**
     * مناديب التوصيل: من يغطّي هذه المحافظة أوّلاً بمناطقه، ثم البقية لمن يريد غيرهم.
     *
     * @return Collection<int, Courier>
     */
    private function couriers(Request $request, ?int $governorateId): Collection
    {
        $covering = $governorateId
            ? CourierZone::where('governorate_id', $governorateId)->pluck('courier_id')->unique()
            : collect();

        return Courier::delivering()->active()->visibleTo($request->user())->orderBy('name')->get(['id', 'name'])
            ->each(fn (Courier $c) => $c->setAttribute('covers', $covering->contains($c->id)))
            ->sortByDesc('covers')->values();
    }

    /** مندوب المنطقة: من سُمّيت له المنطقة نفسها، وإلّا من يغطّي المحافظة كلّها */
    private function suggest(Request $request, Shipment $shipment): ?int
    {
        $zones = CourierZone::where('governorate_id', $shipment->governorate_id)
            ->where(fn ($q) => $q->whereNull('city_id')->when($shipment->city_id, fn ($w) => $w->orWhere('city_id', $shipment->city_id)))
            ->whereHas('courier', fn ($c) => $c->delivering()->active()->visibleTo($request->user()))
            ->orderByRaw('case when city_id is null then 1 else 0 end')
            ->orderBy('id')
            ->first(['courier_id']);

        return $zones?->courier_id;
    }
}
