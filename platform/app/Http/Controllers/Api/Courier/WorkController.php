<?php

namespace App\Http\Controllers\Api\Courier;

use App\Actions\Pickups\AccruePickupShare;
use App\Actions\Shipments\CompletePickup;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\PickupPayout;
use App\Models\PickupRequest;
use App\Models\PickupShare;
use App\Models\Shipment;
use App\Support\Arabic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «اليوم» و«الاستلام» و«حصصي» في تطبيق المندوب (docs/plan/71): شاشاته في الموقع نفسها، بأفعالها
 * نفسها (CompletePickup وAccruePickupShare)، فلا قاعدتان لشيءٍ واحد.
 */
class WorkController extends Controller
{
    /** ما سجّله اليوم: المسلَّم وما لم يُسلَّم وما أُجّل، وما حصّله */
    public function today(Request $request): JsonResponse
    {
        $courier = $this->courier($request);

        $done = Shipment::query()
            ->where('delivery_courier_id', $courier->id)
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereOnDate('status_changed_at', today())
                ->whereIn('status', [
                    ShipmentStatus::Delivered->value,
                    ShipmentStatus::PartiallyDelivered->value,
                    ShipmentStatus::FailedAttempt->value,
                    ShipmentStatus::Postponed->value,
                ]))
                // واصلٌ جزئي سلّمه اليوم ولو مضى باقيه راجعاً: نقده بيده ويُسلَّم معه
                ->orWhere(fn ($w) => $w->whereOnDate('delivered_at', today())))
            ->with(['governorate:id,name_ar', 'city:id,name_ar', 'lastFailureReason:id,name_ar'])
            ->latest('status_changed_at')
            ->get();

        return response()->json([
            'delivered' => $done->filter(fn (Shipment $s) => $s->wasDelivered())->count(),
            'not_delivered' => $done->filter(fn (Shipment $s) => $s->status === ShipmentStatus::FailedAttempt)->count(),
            'postponed' => $done->filter(fn (Shipment $s) => $s->status === ShipmentStatus::Postponed)->count(),
            'collected' => (int) $done->sum('collected_amount'),
            'data' => $done->map(fn (Shipment $s) => ShipmentController::row($s) + [
                'governorate' => $s->governorate?->name_ar,
                'collected' => (int) $s->collected_amount,
                'at' => $s->status_changed_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** طلبات الاستلام المُسنَدة إليه — لمندوب الاستلام */
    public function pickups(Request $request): JsonResponse
    {
        $courier = $this->courier($request);
        abort_unless($courier->picks(), 403, 'الاستلام لمندوب الاستلام.');

        return response()->json([
            'data' => PickupRequest::query()
                ->where('courier_id', $courier->id)
                ->whereIn('status', ['assigned', 'in_progress'])
                ->with('merchant:id,business_name,phone,address,landmark')
                ->orderBy('scheduled_at')
                ->get()
                ->map(fn (PickupRequest $p) => [
                    'id' => $p->id,
                    'number' => $p->number,
                    'merchant' => $p->merchant?->business_name,
                    'expected' => (int) $p->expected_count,
                    'address' => $p->address ?: $p->merchant?->address,
                    'landmark' => $p->landmark ?: $p->merchant?->landmark,
                    'notes' => $p->notes,
                    // يُتّصل به من الزرّ ولا يُنسخ (docs/plan/66 §الأمان)
                    'phone' => $p->contact_phone ?: $p->merchant?->phone,
                    'at' => $p->scheduled_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    /** «استلمت الطرود»: كلّ شحنات التاجر المعلّقة دفعةً واحدة، كما في الموقع */
    public function completePickup(Request $request, PickupRequest $pickup, CompletePickup $action): JsonResponse
    {
        $courier = $this->courier($request);
        abort_unless($pickup->courier_id === $courier->id, 404);

        if ($pickup->status === 'completed') {
            return response()->json(['message' => 'هذا الطلب مُغلق بالفعل.'], 422);
        }

        $data = $request->validate([
            'actual_count' => ['required', 'integer', 'min:0', 'max:5000'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], ['actual_count' => 'العدد المستلم']);

        $action->handle($pickup, $data['actual_count'], $request->user(), $data['note'] ?? null);

        return response()->json([
            'message' => 'استُلم '.Arabic::parcels((int) $data['actual_count'])." من {$pickup->merchant->business_name}.",
        ]);
    }

    /** حصصه من الاستلام طلباً طلباً، ودفعات أرباحه — يعترض على العدد ويؤكّد ما وصله */
    public function shares(Request $request): JsonResponse
    {
        $courier = $this->courier($request);

        return response()->json([
            'balance' => (int) $courier->commission_balance,
            'payouts' => $courier->payouts()->latest('id')->limit(20)->get()
                ->map(fn (PickupPayout $p) => [
                    'id' => $p->id,
                    'number' => $p->number,
                    'paid' => (int) $p->paid_amount,
                    'earned' => (int) $p->earned,
                    'centre' => (int) $p->centre_amount,
                    'confirmed' => $p->confirmed_at !== null,
                    'date' => $p->created_at?->toDateString(),
                ])->values(),
            'shares' => PickupShare::with('pickupRequest:id,number')
                ->where('courier_id', $courier->id)
                ->latest('id')->limit(50)->get()
                ->map(fn (PickupShare $s) => [
                    'id' => $s->id,
                    'pickup' => $s->pickupRequest?->number,
                    'count' => (int) $s->shipments_count,
                    'rate' => (int) $s->rate,
                    'amount' => $s->finalAmount(),
                    'status' => $s->status,
                    'status_label' => $s->statusLabel(),
                    'note' => $s->resolution_note,
                    'can_object' => $s->status === 'accrued',
                    'date' => $s->created_at?->toDateString(),
                ])->values(),
        ]);
    }

    public function objectShare(Request $request, PickupShare $share, AccruePickupShare $action): JsonResponse
    {
        abort_unless((int) $share->courier_id === $this->courier($request)->id, 404);

        $data = $request->validate([
            'claimed_count' => ['required', 'integer', 'min:0', 'max:5000'],
            'reason' => ['required', 'string', 'max:255'],
        ], [], ['claimed_count' => 'العدد الذي جمعته', 'reason' => 'السبب']);

        $action->object($share, (int) $data['claimed_count'], $data['reason']);

        return response()->json(['message' => 'سُجّل اعتراضك. سيُبتّ فيه من إدارة الفرع.']);
    }

    /** «استلمتُها»: مندوب الاستلام يؤكّد دفعة ربحه — مرّةً واحدة */
    public function confirmPayout(Request $request, PickupPayout $payout): JsonResponse
    {
        abort_unless((int) $payout->courier_id === $this->courier($request)->id, 404);

        $confirmed = PickupPayout::whereKey($payout->id)->whereNull('confirmed_at')->update(['confirmed_at' => now()]);

        return $confirmed
            ? response()->json(['message' => "أكّدت استلام الدفعة {$payout->number}."])
            : response()->json(['message' => "الدفعة {$payout->number} مؤكَّدة سلفاً."], 422);
    }

    private function courier(Request $request): Courier
    {
        return $request->attributes->get('courier');
    }
}
