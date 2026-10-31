<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Shipments\SendOutForDelivery;
use App\Http\Controllers\Controller;
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
 * «توزيع بالمسح» (docs/plan/50): يُمسح الوصل فيدخل الجدول، ويُجمَع بمحافظته ومنطقته، ولكلّ منطقةٍ
 * مندوبها: يُقترح من مناطق المناديب ويُبدَّل من القائمة. ثم فعلٌ واحد يُخرج كلّ شحنةٍ مع مندوب منطقتها.
 *
 * المحافظات (docs/plan/98): كلّها، أو ما يُختار منها — والشركة الوسيطة توصل لمحافظاتٍ كثيرة في توزيعٍ واحد.
 * ووصلٌ وجهته خارج ما اختير لا يدخل الجدول.
 *
 * المسح لا يغيّر شيئاً حتى «وزّع»: الجدول في المتصفّح كشاشة الاستلام.
 */
class ShipmentDistributeController extends Controller
{
    public function index(Request $request): View
    {
        $governorates = Governorate::where('is_active', true)->orderedForCompany()->get(['id', 'name_ar']);
        $chosen = $governorates->whereIn('id', $this->chosen($request))->values();

        return view('tenant.shipments.distribute', [
            'governorates' => $governorates,
            'chosen'       => $chosen,
            'scope'        => $this->scopeLabel($chosen),
            'mode'         => $this->mode($request->query('mode')),
            'couriers'     => $this->couriers($request, $chosen->pluck('id'), partners: false),
            'partners'     => $this->couriers($request, $chosen->pluck('id'), partners: true),
        ]);
    }

    /** الوصل لهذا التوزيع: وجهته من المحافظات المختارة (أو أيّ محافظة)، ومنطقته، ومندوبها المقترح */
    public function lookup(Request $request): JsonResponse
    {
        $typed = (string) $request->query('number');
        $chosen = $this->chosen($request);
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

        if ($chosen->isNotEmpty() && ! $chosen->contains((int) $shipment->governorate_id)) {
            return response()->json(['error' => "{$shipment->number} وجهته {$shipment->governorate?->name_ar} — خارج المحافظات المختارة."], 422);
        }

        // المسلَّمة والملغاة وما يشبهها لا تُوزَّع: يُقال عند المسح لا بعد «وزّع»
        if ($shipment->isTerminal()) {
            return response()->json(['error' => "{$shipment->number} حالته «{$shipment->statusLabel()}» — لا يُوزَّع."], 422);
        }

        $phone = (string) $shipment->recipient_phone;
        $partner = $this->mode($request->query('mode')) === 'partner';

        return response()->json([
            'id'          => $shipment->id,
            'number'      => $shipment->number,
            'merchant'    => $shipment->merchant?->business_name,
            'status'      => $shipment->statusLabel(),
            'tone'        => $shipment->statusColor(),
            'amount'      => (int) $shipment->cod_amount,
            // المنطقة بمحافظتها: «بلا منطقة» في البصرة غيرها في بغداد
            'area_id'     => (int) $shipment->governorate_id.':'.(int) $shipment->city_id,
            'area'        => $shipment->city?->name_ar ?? 'بلا منطقة',
            'governorate' => $shipment->governorate?->name_ar,
            'courier'     => $shipment->deliveryCourier?->name,
            // مندوب شركةٍ أخرى لا يُقترح من مناطق مناديبنا
            'suggested'   => $partner ? null : $this->suggest($request, $shipment),
            'phone'       => str_repeat('•', max(0, mb_strlen($phone) - 3)).mb_substr($phone, -3),
            'url'         => route('shipments.show', $shipment),
        ]);
    }

    public function store(Request $request, SendOutForDelivery $sendOut): RedirectResponse
    {
        $data = $request->validate([
            'governorates'   => ['nullable', 'array', 'max:100'],
            'governorates.*' => ['integer'],
            'governorate_id' => ['nullable', 'integer'],
            'mode'           => ['nullable', 'in:own,partner'],
            'courier'        => ['required', 'array', 'min:1', 'max:500'],
            'courier.*'      => ['nullable', 'integer'],
        ], [], ['courier' => 'الوصولات']);

        $mode = $this->mode($data['mode'] ?? null);
        $chosen = $this->chosen($request);
        $back = redirect()->route('shipments.distribute', ['governorates' => $chosen->all(), 'mode' => $mode]);

        // لكلّ وصلٍ مندوبه: [رقم الشحنة => رقم المندوب]
        $plan = collect($data['courier'])->mapWithKeys(fn ($c, $id) => [(int) $id => (int) $c]);

        if ($plan->contains(0)) {
            return $back->withErrors(['courier' => 'اختر مندوباً لكلّ منطقة قبل التوزيع.']);
        }

        // مندوبو الشركة لا يُسنَدون لمندوب شركةٍ أخرى والعكس: كلٌّ في قائمته
        $couriers = Courier::delivering()->active()->visibleTo($request->user())
            ->where('is_intermediary', $mode === 'partner')
            ->whereIn('id', $plan->unique()->values())->get()->keyBy('id');

        // ما لا يراه لا يوزَّع باسمه، وما وجهته خارج المحافظات المختارة لا يدخل هذا التوزيع
        $shipments = Shipment::whereIn('id', $plan->keys())
            ->visibleTo($request->user())
            ->when($chosen->isNotEmpty(), fn ($q) => $q->whereIn('governorate_id', $chosen))
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
            $skipped['#'.$missing] = $chosen->isNotEmpty() ? 'خارج المحافظات المختارة' : 'غير متاحة';
        }

        $message = $moved
            ? ($mode === 'partner'
                ? 'أُرسلت '.Arabic::shipments($moved).' إلى مندوب شركةٍ أخرى.'
                : 'وُزّعت '.Arabic::shipments($moved).' على مناديبها.')
            : 'لم تُوزَّع أيّ شحنة.';

        if ($skipped) {
            $message .= ' تُخطّيت '.count($skipped).': '.collect($skipped)->take(6)
                ->map(fn ($reason, $number) => "{$number} ({$reason})")->implode('، ').(count($skipped) > 6 ? '…' : '');
        }

        return $moved ? $back->with('success', $message) : $back->withErrors(['courier' => $message]);
    }

    /** توزيعٌ على مندوبي الشركة، أو إلى مندوب شركةٍ أخرى (شركة وسيطة) */
    private function mode(?string $mode): string
    {
        return $mode === 'partner' ? 'partner' : 'own';
    }

    /**
     * المحافظات المختارة، وفارغةٌ لكلّها: governorates[] من النموذج، أو governorates=1,5 من المسح، أو
     * governorate=1 من رابطٍ قديم.
     *
     * @return Collection<int, int>
     */
    private function chosen(Request $request): Collection
    {
        $many = $request->input('governorates', []);
        $many = is_array($many) ? $many : explode(',', (string) $many);

        return collect($many)
            ->push($request->input('governorate'), $request->input('governorate_id'))
            ->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values();
    }

    /** «كل المحافظات»، أو أسماؤها حتى ثلاث، أو عددها */
    private function scopeLabel(Collection $chosen): string
    {
        return match (true) {
            $chosen->isEmpty() => 'كل المحافظات',
            $chosen->count() <= 3 => $chosen->pluck('name_ar')->implode('، '),
            default => Arabic::count($chosen->count(), ['محافظة واحدة', 'محافظتان', 'محافظات', 'محافظة']),
        };
    }

    /**
     * مناديب التوصيل: من يغطّي المحافظات المختارة أوّلاً بمناطقه، ثم البقية لمن يريد غيرهم. وفي كلّ المحافظات
     * من له مناطق أوّلاً.
     *
     * @param  Collection<int, int>  $governorateIds
     * @return Collection<int, Courier>
     */
    private function couriers(Request $request, Collection $governorateIds, bool $partners): Collection
    {
        $covering = CourierZone::query()
            ->when($governorateIds->isNotEmpty(), fn ($q) => $q->whereIn('governorate_id', $governorateIds))
            ->pluck('courier_id')->unique();

        return Courier::delivering()->active()->where('is_intermediary', $partners)->visibleTo($request->user())
            ->orderBy('name')->get(['id', 'name'])
            ->each(fn (Courier $c) => $c->setAttribute('covers', $covering->contains($c->id)))
            ->sortByDesc('covers')->values();
    }

    /** مندوب المنطقة: من سُمّيت له المنطقة نفسها، وإلّا من يغطّي المحافظة كلّها */
    private function suggest(Request $request, Shipment $shipment): ?int
    {
        $zones = CourierZone::where('governorate_id', $shipment->governorate_id)
            ->where(fn ($q) => $q->whereNull('city_id')->when($shipment->city_id, fn ($w) => $w->orWhere('city_id', $shipment->city_id)))
            ->whereHas('courier', fn ($c) => $c->delivering()->active()->where('is_intermediary', false)->visibleTo($request->user()))
            ->orderByRaw('case when city_id is null then 1 else 0 end')
            ->orderBy('id')
            ->first(['courier_id']);

        return $zones?->courier_id;
    }
}
