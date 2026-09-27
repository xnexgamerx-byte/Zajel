<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ما طلبه التجّار من بواباتهم، كما في المعتاد:
 *
 * «طلبات حساب من العملاء لم يتمّ معاملتها» — ببطاقتين: كم بانتظار الدفع من
 * مبالغ شحناتهم، وكم يستحقّون فعلاً بعد استقطاع ما عليهم. والطلب يُغلَق وحده
 * حين يُبنى كشفه.
 *
 * و«طلبات كشف راجع للعملاء» — وكم على الرفّ لكلٍّ منهم الآن، ويُغلَق وحده
 * حين تُسلَّم رواجعه.
 */
class MerchantRequestController extends Controller
{
    public function payments(Request $request): View
    {
        $requests = $this->filtered($request, 'payment')
            ->when(array_key_exists((string) $request->query('payout_method'), Merchant::PAYOUT_METHODS),
                fn ($q) => $q->where('merchant_requests.payout_method', $request->query('payout_method')));

        // البطاقتان على الطلبات المفتوحة وحدها: ما ينتظر اليوم
        $waiting = MerchantRequest::query()->open()->ofType('payment')->select('merchant_id');

        return view('tenant.merchant-requests.payments', [
            'requests' => $requests->paginate(config('zajel.per_page'))->withQueryString(),
            'gross'    => (int) Shipment::query()
                ->whereIn('shipments.merchant_id', $waiting)
                ->whereNull('shipments.merchant_settlement_id')
                ->whereIn('shipments.status', [ShipmentStatus::Delivered->value, ShipmentStatus::PartiallyDelivered->value])
                ->sum('shipments.collected_amount'),
            'net'      => (int) Merchant::query()->whereIn('id', $waiting)->where('balance', '>', 0)->sum('balance'),
            'open'     => MerchantRequest::query()->open()->ofType('payment')->count(),
            ...$this->filters(),
        ]);
    }

    public function returns(Request $request): View
    {
        $requests = $this->filtered($request, 'returns')->paginate(config('zajel.per_page'))->withQueryString();

        // كم على رفّ فرع التاجر جاهزاً للتسليم، لكل تاجرٍ في الصفحة
        $ready = Shipment::query()
            ->whereIn('shipments.merchant_id', $requests->pluck('merchant_id')->unique())
            ->returnOnShelf()
            ->awayFromHomeBranch(false)
            ->selectRaw('shipments.merchant_id, count(*) as total')
            ->groupBy('shipments.merchant_id')
            ->pluck('total', 'merchant_id');

        return view('tenant.merchant-requests.returns', [
            'requests' => $requests,
            'ready'    => $ready,
            'open'     => MerchantRequest::query()->open()->ofType('returns')->count(),
            ...$this->filters(),
        ]);
    }

    public function handlePayment(Request $request, MerchantRequest $merchantRequest): RedirectResponse
    {
        return $this->close($request, $merchantRequest, 'payment');
    }

    public function handleReturns(Request $request, MerchantRequest $merchantRequest): RedirectResponse
    {
        return $this->close($request, $merchantRequest, 'returns');
    }

    /**
     * عولج بغير الطريق المعتاد (بالهاتف، أو لا شيء يُسلَّم): يُغلق باسم من أغلقه.
     * ولكلّ نوعٍ مساره وصلاحيته: مسؤول الراجع لا يغلق طلب مال.
     */
    private function close(Request $request, MerchantRequest $merchantRequest, string $type): RedirectResponse
    {
        abort_unless($merchantRequest->type === $type, 404);

        $closed = MerchantRequest::whereKey($merchantRequest->id)->open()->update([
            'status' => 'handled', 'handled_at' => now(), 'handled_by_user_id' => $request->user()->id, 'updated_at' => now(),
        ]);

        return $closed
            ? back()->with('success', "أُغلق الطلب {$merchantRequest->number}.")
            : back()->withErrors(['request' => "الطلب {$merchantRequest->number} ليس مفتوحاً."]);
    }

    private function filtered(Request $request, string $type): Builder
    {
        $status = $request->query('status', 'open');

        return MerchantRequest::query()
            ->ofType($type)
            ->with([
                'merchant:id,business_name,code,phone,address,balance,governorate_id,city_id,pickup_courier_id',
                'merchant.governorate:id,name_ar', 'merchant.city:id,name_ar', 'merchant.pickupCourier:id,name',
                'settlement:id,code,status', 'returnBatch:id,number', 'handledBy:id,name',
            ])
            ->when(in_array($status, ['open', 'handled'], true), fn ($q) => $q->where('merchant_requests.status', $status))
            // «عرض الطلبات الملغاة؟» — مخفيّةٌ إلّا إن طُلبت
            ->when($status === 'all' && ! $request->boolean('cancelled'), fn ($q) => $q->where('merchant_requests.status', '!=', 'cancelled'))
            ->when($request->integer('merchant_id'), fn ($q, $id) => $q->where('merchant_requests.merchant_id', $id))
            ->when($request->integer('pickup_courier_id'), fn ($q, $id) => $q->whereIn('merchant_requests.merchant_id',
                Merchant::query()->select('id')->where('pickup_courier_id', $id)))
            ->oldest('merchant_requests.id');
    }

    private function filters(): array
    {
        return [
            'merchants' => Merchant::orderBy('business_name')->get(['id', 'business_name']),
            'couriers'  => Courier::picking()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
