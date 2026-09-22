<?php

namespace App\Http\Controllers\Courier;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\FailureReason;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ما يستطيع المندوب فعله بشحنة بيده — ولا شيء غيره.
 *
 * أربعة انتقالات فقط، وكلّها من "مع المندوب". لا يستطيع إرجاع شحنة
 * ولا إلغاءها ولا تعديل مبلغها: هذه قرارات الشركة، والمندوب ينفّذ
 * ويسجّل ما جرى عند الباب.
 */
class ActionController extends Controller
{
    public function __construct(protected ChangeShipmentStatus $changeStatus) {}

    private const ALLOWED = [
        'delivered'           => ShipmentStatus::Delivered,
        'partially_delivered' => ShipmentStatus::PartiallyDelivered,
        'failed_attempt'      => ShipmentStatus::FailedAttempt,
        'postponed'           => ShipmentStatus::Postponed,
    ];

    public function __invoke(Request $request, Shipment $shipment): RedirectResponse
    {
        $courier = $request->attributes->get('courier');

        abort_unless($shipment->delivery_courier_id === $courier->id, 404);

        $data = $request->validate([
            'action'            => ['required', Rule::in(array_keys(self::ALLOWED))],
            'collected_amount'  => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'failure_reason_id' => ['nullable', 'integer'],
            'note'              => ['nullable', 'string', 'max:500'],
            'lat'               => ['nullable', 'numeric', 'between:-90,90'],
            'lng'               => ['nullable', 'numeric', 'between:-180,180'],
        ], [], [
            'collected_amount'  => 'المبلغ المستلم',
            'failure_reason_id' => 'السبب',
            'note'              => 'الملاحظة',
        ]);

        $to = self::ALLOWED[$data['action']];

        if ($shipment->status !== ShipmentStatus::OutForDelivery) {
            return back()->withErrors(['action' => 'هذه الشحنة لم تعد بيدك.']);
        }

        $errors = $this->guard($request, $shipment, $to, $data);

        if ($errors) {
            return back()->withErrors($errors)->withInput();
        }

        $this->changeStatus->handle($shipment, $to, $request->user(), array_filter([
            'actor_type'        => 'courier',
            'courier_id'        => $courier->id,
            'collected_amount'  => $data['collected_amount'] ?? null,
            'failure_reason_id' => $data['failure_reason_id'] ?? null,
            'note'              => $data['note'] ?? null,
            'lat'               => $data['lat'] ?? null,
            'lng'               => $data['lng'] ?? null,
        ], fn ($v) => $v !== null));

        return redirect()
            ->route('courier.tasks')
            ->with('success', "سُجِّلت الشحنة {$shipment->number}: {$to->label()}.");
    }

    /** @return array<string,string> */
    protected function guard(Request $request, Shipment $shipment, ShipmentStatus $to, array $data): array
    {
        if (in_array($to, [ShipmentStatus::FailedAttempt, ShipmentStatus::Postponed], true)) {
            $reason = isset($data['failure_reason_id'])
                ? FailureReason::availableFor($request->user()->company_id)
                    ->whereKey($data['failure_reason_id'])->first()
                : null;

            if (! $reason) {
                return ['failure_reason_id' => 'اختر السبب — بلا سبب لا يعرف التاجر لماذا لم تُسلَّم.'];
            }

            if ($reason->requires_note && ! trim((string) ($data['note'] ?? ''))) {
                return ['note' => "السبب «{$reason->name_ar}» يحتاج توضيحاً."];
            }
        }

        if ($to === ShipmentStatus::PartiallyDelivered && ! isset($data['collected_amount'])) {
            return ['collected_amount' => 'أدخل المبلغ الذي استلمته فعلاً.'];
        }

        $collected = $data['collected_amount'] ?? null;

        if ($collected !== null && $collected > $shipment->cod_amount) {
            return ['collected_amount' => 'المبلغ أكبر من المطلوب من الزبون.'];
        }

        return [];
    }
}
