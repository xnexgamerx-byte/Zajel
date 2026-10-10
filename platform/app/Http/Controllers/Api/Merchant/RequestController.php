<?php

namespace App\Http\Controllers\Api\Merchant;

use App\Actions\Merchants\SubmitMerchantRequest;
use App\Actions\Returns\HandOverReturns;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantRequest;
use App\Models\ReturnBatch;
use App\Models\Shipment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «طلباتي» في تطبيق التاجر (docs/plan/56): طلباته (الدفع من «المالية»، وكشف الراجع من هنا)
 * وإلغاء المفتوح منها، وإيصالات الراجع التي سُلّمت له و«وصلتني» — كما في بوابته.
 */
class RequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        return response()->json([
            // ما يرجع إليه الآن: طلب كشف الراجع يجمعه
            'returning' => Shipment::where('merchant_id', $merchant->id)->where('status', ShipmentStatus::Returning->value)->count(),
            'requests' => MerchantRequest::where('merchant_id', $merchant->id)
                ->with(['settlement:id,code', 'returnBatch:id,number'])->latest('id')->limit(30)->get()
                ->map(fn (MerchantRequest $r) => [
                    'id' => $r->id,
                    'number' => $r->number,
                    'type' => $r->type,
                    'label' => $r->typeLabel(),
                    'status' => $r->status,
                    'state' => $r->statusLabel(),
                    'amount' => $r->type === 'payment' && $r->amount !== null ? (int) $r->amount : null,
                    'method' => $r->type === 'payment' ? (Merchant::PAYOUT_METHODS[$r->payout_method] ?? $r->payout_method) : null,
                    'courier' => (bool) $r->via_pickup_courier,
                    'note' => $r->note,
                    'result' => $r->settlement ? "كشف {$r->settlement->code}" : ($r->returnBatch ? "إيصال {$r->returnBatch->number}" : null),
                    'at' => $r->created_at?->toIso8601String(),
                ])->values(),
            'batches' => ReturnBatch::where('merchant_id', $merchant->id)->with('courier:id,name')->withCount('shipments')
                ->latest('id')->limit(30)->get()
                ->map(fn (ReturnBatch $b) => [
                    'id' => $b->id,
                    'number' => $b->number,
                    'count' => (int) $b->shipments_count,
                    'fees' => (int) $b->return_fees_total,
                    'via' => $b->viaLabel(),
                    'at' => $b->handed_at?->toIso8601String(),
                    'received' => $b->received_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    /** طلب كشف راجع — وطلب الدفع من «المالية» */
    public function store(Request $request, SubmitMerchantRequest $submit): JsonResponse
    {
        $data = $request->validate([
            'via_pickup_courier' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['note' => 'الملاحظة']);

        $made = $submit->handle($request->attributes->get('merchant'), 'returns', $data, $request->user());

        return response()->json(['number' => $made->number, 'message' => "أُرسل {$made->typeLabel()} برقم {$made->number}."], 201);
    }

    public function cancel(Request $request, MerchantRequest $merchantRequest): JsonResponse
    {
        abort_unless((int) $merchantRequest->merchant_id === (int) $request->attributes->get('merchant')->id, 404);

        $cancelled = MerchantRequest::whereKey($merchantRequest->id)->open()
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);

        return $cancelled
            ? response()->json(['message' => "أُلغي الطلب {$merchantRequest->number}."])
            : response()->json(['message' => "الطلب {$merchantRequest->number} عولج سلفاً فلا يُلغى."], 422);
    }

    /**
     * تفاصيل إيصال الراجع (docs/plan/61): شحناته واحدةً واحدة — المستلم والوجهة والمبلغ وسبب الرجوع
     * وأجرته — كما طُبعت على الإيصال. إيصال تاجرٍ آخر «غير موجود».
     */
    public function batch(Request $request, ReturnBatch $batch): JsonResponse
    {
        abort_unless((int) $batch->merchant_id === (int) $request->attributes->get('merchant')->id, 404);

        $shipments = $batch->shipments()
            ->with(['governorate:id,name_ar', 'city:id,name_ar', 'lastFailureReason:id,name_ar'])
            ->orderBy('id')->limit(1000)->get();

        return response()->json([
            'id' => $batch->id,
            'number' => $batch->number,
            'count' => (int) $batch->shipments_count,
            'fees' => (int) $batch->return_fees_total,
            'via' => $batch->load('courier:id,name')->viaLabel(),
            'note' => $batch->note,
            'at' => $batch->handed_at?->toIso8601String(),
            'received' => $batch->received_at?->toIso8601String(),
            'shipments' => $shipments->map(fn (Shipment $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'name' => $s->recipient_name ?: $s->recipient_phone,
                'place' => collect([$s->governorate?->name_ar, $s->city?->name_ar])->filter()->implode(' · '),
                'amount' => (int) $s->cod_amount,
                'reason' => $s->returnReason(),
                'fee' => (int) $s->return_fee,
            ])->values(),
        ]);
    }

    /** ما حمله مندوب الاستلام وصلني */
    public function confirm(Request $request, ReturnBatch $batch, HandOverReturns $handover): JsonResponse
    {
        abort_unless((int) $batch->merchant_id === (int) $request->attributes->get('merchant')->id, 404);

        return $handover->confirmReceived($batch, 'التاجر من التطبيق', $request->user(), 'merchant')
            ? response()->json(['message' => "أكّدت استلام رواجع الإيصال {$batch->number}."])
            : response()->json(['message' => "الإيصال {$batch->number} مؤكَّدٌ سلفاً."], 422);
    }
}
