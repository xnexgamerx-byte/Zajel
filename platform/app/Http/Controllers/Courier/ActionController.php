<?php

namespace App\Http\Controllers\Courier;

use App\Actions\Shipments\ChangeShipmentStatus;
use App\Actions\Shipments\CourierOutcomeRules;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ما يستطيع المندوب فعله بشحنة بيده — ولا شيء غيره.
 *
 * أربعة انتقالات فقط، وكلّها من "مع المندوب". لا يستطيع إرجاع شحنة
 * ولا إلغاءها ولا تعديل مبلغها: هذه قرارات الشركة، والمندوب ينفّذ
 * ويسجّل ما جرى عند الباب.
 *
 * والمبلغ لا يُكتب هنا (docs/plan/30): «واصل» بمبلغ الشحنة كما هو، و«واصل جزئي»
 * بما اعتمده الكول سنتر في طلب تغيير المبلغ وحده. كان المندوب يكتب ما يشاء
 * ولا يصل الشركة شيء.
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
            // صفحةٌ قديمة فيها خانة المبلغ: يُقرأ ليُرفض إن غيّره، لا ليُقيَّد
            'collected_amount'  => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'failure_reason_id' => ['nullable', 'integer'],
            'note'              => ['nullable', 'string', 'max:500'],
            'delivery_code'     => ['nullable', 'string', 'max:10'],
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

        // الواصل الجزئي بالمبلغ الذي اعتمده الكول سنتر، ولا غيره
        $partial = $to === ShipmentStatus::PartiallyDelivered
            ? ShipmentTicket::query()->where('shipment_id', $shipment->id)->where('courier_id', $courier->id)
                ->awaitingPartial()->latest('id')->first()
            : null;

        $errors = $this->guard($request, $shipment, $to, $data, $partial);

        if ($errors) {
            return back()->withErrors($errors)->withInput();
        }

        $done = $this->changeStatus->handle($shipment, $to, $request->user(), array_filter([
            'actor_type'        => 'courier',
            'courier_id'        => $courier->id,
            'collected_amount'  => $partial?->approved_amount,
            'ticket_id'         => $partial?->id,
            'failure_reason_id' => $data['failure_reason_id'] ?? null,
            'note'              => $data['note'] ?? null,
            'lat'               => $data['lat'] ?? null,
            'lng'               => $data['lng'] ?? null,
        ], fn ($v) => $v !== null));

        // الاستبدال يُسجَّل «واصل جزئي»: قديمه بيد المندوب، يُسلَّم للمخزن مع الراجع (الوثيقة ٣١)
        $message = $done->type === 'exchange' && $done->status === ShipmentStatus::PartiallyDelivered
            ? "سُجِّل استبدال الشحنة {$shipment->number}. سلّم القطعة القديمة للمخزن مع الراجع."
            : "سُجِّلت الشحنة {$shipment->number}: {$done->status->label()}.";

        return redirect()
            ->route('courier.tasks')
            ->with('success', $message);
    }

    /** @return array<string,string> */
    protected function guard(Request $request, Shipment $shipment, ShipmentStatus $to, array $data, ?ShipmentTicket $partial): array
    {
        return CourierOutcomeRules::errors($shipment, $to, $data, $partial);
    }
}
