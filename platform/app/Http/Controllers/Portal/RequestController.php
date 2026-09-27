<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Merchants\SubmitMerchantRequest;
use App\Actions\Returns\HandOverReturns;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\ReturnBatch;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * «طلباتي» في بوابة التاجر: طلب دفعٍ برقم، وطلب كشف راجع، ودفعات الراجع
 * التي سُلّمت له بإيصالاتها — يؤكّد منها ما وصله مع مندوب الاستلام.
 */
class RequestController extends Controller
{
    public function index(Request $request): View
    {
        $merchant = $request->attributes->get('merchant');

        return view('portal.requests.index', [
            'requests' => MerchantRequest::where('merchant_id', $merchant->id)
                ->with(['settlement:id,code,status', 'returnBatch:id,number'])
                ->latest('id')->limit(30)->get(),
            'batches'  => ReturnBatch::where('merchant_id', $merchant->id)
                ->with('courier:id,name')
                ->latest('id')
                ->paginate(config('zajel.per_page'))
                ->withQueryString(),
            'returning' => Shipment::where('merchant_id', $merchant->id)
                ->where('status', ShipmentStatus::Returning->value)->count(),
            'methods'  => Merchant::PAYOUT_METHODS,
        ]);
    }

    public function store(Request $request, SubmitMerchantRequest $submit): RedirectResponse
    {
        $data = $request->validate([
            'type'               => ['required', Rule::in(array_keys(MerchantRequest::TYPES))],
            'payout_method'      => ['nullable', Rule::in(array_keys(Merchant::PAYOUT_METHODS))],
            'via_pickup_courier' => ['sometimes', 'boolean'],
            'note'               => ['nullable', 'string', 'max:500'],
        ], [], ['payout_method' => 'طريقة الدفع', 'note' => 'الملاحظة']);

        $made = $submit->handle($request->attributes->get('merchant'), $data['type'], $data, $request->user());

        return back()->with('success', "أُرسل {$made->typeLabel()} برقم {$made->number}.");
    }

    public function cancel(Request $request, MerchantRequest $merchantRequest): RedirectResponse
    {
        abort_unless((int) $merchantRequest->merchant_id === (int) $request->attributes->get('merchant')->id, 404);

        $cancelled = MerchantRequest::whereKey($merchantRequest->id)->open()
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);

        return $cancelled
            ? back()->with('success', "أُلغي الطلب {$merchantRequest->number}.")
            : back()->withErrors(['type' => "الطلب {$merchantRequest->number} عولج سلفاً فلا يُلغى."]);
    }

    /** ما حمله مندوب الاستلام وصلني: التاجر يؤكّده بنفسه */
    public function confirm(Request $request, ReturnBatch $batch, HandOverReturns $handover): RedirectResponse
    {
        abort_unless((int) $batch->merchant_id === (int) $request->attributes->get('merchant')->id, 404);

        return $handover->confirmReceived($batch, 'التاجر من بوابته')
            ? back()->with('success', "أكّدت استلام رواجع الإيصال {$batch->number}.")
            : back()->withErrors(['batch' => "الإيصال {$batch->number} مؤكَّدٌ سلفاً."]);
    }

    public function print(Request $request, ReturnBatch $batch): View
    {
        abort_unless((int) $batch->merchant_id === (int) $request->attributes->get('merchant')->id, 404);

        $batch->load([
            'merchant:id,business_name,code,phone,address,city_id', 'merchant.city:id,name_ar',
            'courier:id,name,phone', 'handedBy:id,name',
            'shipments' => fn ($q) => $q->with(['governorate:id,name_ar', 'lastFailureReason:id,name_ar'])->orderBy('id'),
        ]);

        return view('tenant.returns.receipt', ['batches' => collect([$batch]), 'back' => route('portal.requests.index')]);
    }
}
