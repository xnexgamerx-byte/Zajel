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
            // ما تعرضه الشركة للطلب الجديد، وما يُكتب لكلٍّ منها (docs/plan/44)
            'offered'  => \App\Support\PayoutMethods::offered(),
            'hints'    => \App\Support\PayoutMethods::DETAILS_HINT,
        ]);
    }

    public function store(Request $request, SubmitMerchantRequest $submit): RedirectResponse
    {
        $data = $request->validate([
            'type'               => ['required', Rule::in(array_keys(MerchantRequest::TYPES))],
            // طلب الدفع بطريقةٍ تعرضها الشركة، وتفاصيلها لكلّ ما سوى النقد (docs/plan/44)
            'payout_method'      => ['nullable', Rule::in(array_keys(\App\Support\PayoutMethods::offered()))],
            'payout_details'     => ['nullable', 'string', 'max:255'],
            'via_pickup_courier' => ['sometimes', 'boolean'],
            'note'               => ['nullable', 'string', 'max:500'],
        ], [
            'payout_method.in'          => 'طريقة الدفع هذه لا تتعامل بها الشركة — اختر غيرها.',
            'payout_details.required'   => 'اكتب رقم البطاقة أو المحفظة واسم صاحبها.',
        ], ['payout_method' => 'طريقة الدفع', 'payout_details' => 'تفاصيل الدفع', 'note' => 'الملاحظة']);

        $merchant = $request->attributes->get('merchant');

        // بطاقةٌ أو محفظة بلا رقمٍ مكتوب ولا محفوظٍ للتاجر: لا تصل الشركةَ إلّا ناقصة
        if ($data['type'] === 'payment') {
            $method = $data['payout_method'] ?? $merchant->payout_method;

            if (\App\Support\PayoutMethods::needsDetails($method) && blank($data['payout_details'] ?? null) && blank($merchant->payout_account)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['payout_details' => 'اكتب رقم البطاقة أو المحفظة واسم صاحبها.']);
            }
        }

        $made = $submit->handle($merchant, $data['type'], $data, $request->user());

        if ($made->type === 'payment') {
            // «تمّ الطلب»: رقمه ومبلغه وتاريخه أمام التاجر
            return back()->with('payment_request', [
                'number' => $made->number,
                'amount' => (int) $made->amount,
                'date'   => $made->created_at->timezone('Asia/Baghdad')->format('Y-m-d H:i'),
                'method' => Merchant::PAYOUT_METHODS[$made->payout_method] ?? $made->payout_method,
            ]);
        }

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

        return $handover->confirmReceived($batch, 'التاجر من بوابته', $request->user(), 'merchant')
            ? back()->with('success', "أكّدت استلام رواجع الإيصال {$batch->number}.")
            : back()->withErrors(['batch' => "الإيصال {$batch->number} مؤكَّدٌ سلفاً."]);
    }

    public function print(Request $request, ReturnBatch $batch): View
    {
        abort_unless((int) $batch->merchant_id === (int) $request->attributes->get('merchant')->id, 404);

        $batch->load([
            'merchant:id,business_name,code,phone,address,city_id', 'merchant.city:id,name_ar',
            'courier:id,name,phone', 'handedBy:id,name',
            'shipments' => fn ($q) => $q->with(['governorate:id,name_ar', 'city:id,name_ar', 'lastFailureReason:id,name_ar'])->orderBy('id'),
        ]);

        return view('tenant.returns.receipt', ['batches' => collect([$batch]), 'back' => route('portal.requests.index')]);
    }
}
